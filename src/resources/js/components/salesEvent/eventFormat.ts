/**
 * イベント（B-129、K-112）の表示の整え方。
 */

import type { SalesEventStatus } from "@/types/api";

/** モールの選択肢（店舗売上と同じ順、K-093）。楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS で受注する。 */
export const MALLS = [
    { value: "rakuten", label: "楽天市場", channel: "boss" },
    { value: "amazon", label: "Amazon", channel: "amazon" },
    { value: "yahoo", label: "Yahoo!ショッピング", channel: "boss" },
    { value: "au_pay", label: "au PAY マーケット", channel: "boss" },
] as const;

export type MallValue = (typeof MALLS)[number]["value"];

export const isMall = (value: unknown): value is MallValue => MALLS.some((mall) => mall.value === value);

export const CHANNEL_LABELS = { boss: "BOSS", amazon: "Amazon" } as const;

/** 倍率の上限（サーバーと同じ）。 */
export const MAX_MULTIPLIER = 50;

const date = (value: string): { year: number; month: number; day: number } => ({ year: Number(value.slice(0, 4)), month: Number(value.slice(5, 7)), day: Number(value.slice(8, 10)) });

/** 「2026/12/4〜12/11」（年をまたぐなら終わりにも年を付ける）。 */
export function periodLabel(startsOn: string, endsOn: string): string {
    const from = date(startsOn);
    const to = date(endsOn);
    const end = from.year === to.year ? `${to.month}/${to.day}` : `${to.year}/${to.month}/${to.day}`;
    return startsOn === endsOn ? `${from.year}/${from.month}/${from.day}` : `${from.year}/${from.month}/${from.day}〜${end}`;
}

/** 初日から最終日までの日数（両方を含む）。日付が足りなければ null。 */
export function daysBetween(startsOn: string, endsOn: string): number | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(startsOn) || !/^\d{4}-\d{2}-\d{2}$/.test(endsOn)) {
        return null;
    }
    return Math.round((Date.parse(endsOn) - Date.parse(startsOn)) / 86_400_000) + 1;
}

/** 「4.67倍」。出せなければ「—」。 */
export const formatMultiplier = (value: number | null): string => (value === null ? "—" : `${value.toFixed(2)}倍`);

const count = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });
export const formatCount = (value: number | null): string => (value === null ? "—" : count.format(Math.round(value)));

/** 倍率で増える数（倍率が1未満なら減る）。「+40」「−1」「0」。 */
export function formatChange(value: number | null): string {
    if (value === null) {
        return "—";
    }
    const rounded = Math.round(value);
    return rounded > 0 ? `+${count.format(rounded)}` : rounded < 0 ? `−${count.format(-rounded)}` : "0";
}

/**
 * 入力した倍率を読む。空欄は null（システムの候補を使う）、読めない値・範囲外・小数3桁以上は undefined。
 */
export function parseMultiplier(input: string): number | null | undefined {
    const text = input.trim();
    if (text === "") {
        return null;
    }
    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return undefined;
    }
    const value = Number(text);
    return value <= MAX_MULTIPLIER ? value : undefined;
}

/** 期間の状態の色（開催中は緑、これからは青、終了は灰色）。 */
export const statusClass = (status: SalesEventStatus): string =>
    status === "ongoing" ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : status === "upcoming" ? "bg-sky-50 text-sky-800 ring-sky-200" : "bg-stone-100 text-stone-600 ring-stone-200";

let flash: string | null = null;

/** 次に開くイベントの画面に出すお知らせ（登録・削除してから画面を移るとき）。 */
export function setEventFlash(message: string): void {
    flash = message;
}

/** お知らせを受け取る（一度だけ）。 */
export function takeEventFlash(): string | null {
    const message = flash;
    flash = null;
    return message;
}
