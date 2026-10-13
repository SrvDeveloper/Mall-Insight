import type { BacktestHorizon, BacktestMethod, BacktestMetrics } from "@/types/api";

/**
 * 予測精度（B-130）の表示の整え方。WAPE は外れ率、偏りは多め（＋）・少なめ（－）を百分率で出す。販売実績が0で出せないときは「—」。
 */

export const METHODS: { value: BacktestMethod; label: string; short: string }[] = [
    { value: "seasonal", label: "今の方式（イベント＋季節係数）", short: "今の方式" },
    { value: "event_adjusted", label: "イベントの補正だけ", short: "イベントだけ" },
    { value: "base_average", label: "基準平均日販（補正なし）", short: "補正なし" },
    { value: "recent_30", label: "直近30日の平均", short: "直近30日" },
    { value: "last_year", label: "前年同月の実績", short: "前年同月" },
];

const percent = new Intl.NumberFormat("ja-JP", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const count = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });

export const formatWape = (wape: number | null): string => (wape === null ? "—" : `${percent.format(wape * 100)}%`);

export function formatBias(bias: number | null): string {
    if (bias === null) {
        return "—";
    }
    const value = percent.format(Math.abs(bias) * 100);
    return bias > 0.0005 ? `+${value}%` : bias < -0.0005 ? `−${value}%` : `${value}%`;
}

/** 偏りの読み方（多め・少なめ）。 */
export const biasLabel = (bias: number | null): string => (bias === null ? "" : bias > 0.0005 ? "多めに予測" : bias < -0.0005 ? "少なめに予測" : "偏りなし");

export const formatCount = (value: number): string => count.format(Math.round(value));

/**
 * その記録で測った方式。イベントの補正（B-129）より前に測った記録には、今の方式（イベントの補正あり）が無い。
 * 1件も比べていない方式は測っていないものとして外す（どれも比べていなければ、すべて出す）。
 */
export function measuredMethods(methods: { method: BacktestMethod; evaluated: number; skipped: number }[]): typeof METHODS {
    const measured = METHODS.filter((method) => methods.some((item) => item.method === method.value && item.evaluated + item.skipped > 0));
    return measured.length > 0 ? measured : METHODS;
}

/** WAPE がいちばん小さい方式（比べられるものが無ければ null）。同じなら並びの先（今の方式）を選ぶ。 */
export function bestMethod(methods: Partial<Record<BacktestMethod, BacktestMetrics>>, candidates: typeof METHODS = METHODS): BacktestMethod | null {
    let best: BacktestMethod | null = null;
    for (const method of candidates) {
        const wape = methods[method.value]?.wape ?? null;
        if (wape !== null && (best === null || wape < (methods[best]?.wape ?? Infinity) - 1e-9)) {
            best = method.value;
        }
    }
    return best;
}

const monthLabel = (date: string): string => `${date.slice(0, 4)}/${Number(date.slice(5, 7))}`;

function addMonths(date: string, months: number): string {
    const [year, month] = date.split("-").map(Number);
    const index = year! * 12 + (month! - 1) + months;
    return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, "0")}-01`;
}

/** 起点から答え合わせした月（1か月目は起点の月、1～3か月目はその3か月、判定する月は起点の月から判定する月の数だけ先）。 */
export function evaluatedMonths(origin: string, horizon: BacktestHorizon, checkMonthOffset: number): string {
    if (horizon === "months_3") {
        return `${monthLabel(origin)}〜${monthLabel(addMonths(origin, 2))}`;
    }
    return monthLabel(horizon === "check_month" ? addMonths(origin, checkMonthOffset) : origin);
}

export const originLabel = monthLabel;
