import type { ItemTrend, MonthTrend, SkuTrend } from "@/types/api";

/**
 * 12か月在庫推移（B-008）の表示用データ。表・タイムライン・グラフの3つの見せ方（K-047）で共通に使う。
 * データを読み込んだときに一度だけ作り、表示の切り替えや絞り込みでは作り直さない（表が大きいため）。
 */

const quantityFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });
export const formatQuantity = (value: number): string => quantityFormat.format(Math.round(value));
/** 足りない数が、整数に丸めて1個以上か（K-044）。1個未満は「−0」にせず欠品とみなさない。 */
export const isShort = (shortfall: number): boolean => Math.round(shortfall) >= 1;
export const monthLabel = (month: string): string => `${Number(month.slice(5, 7))}月`;
export const shortMonthLabel = (month: string): string => `${month.slice(2, 4)}/${Number(month.slice(5, 7))}`;
export const longMonthLabel = (month: string): string => `${month.slice(0, 4)}年${Number(month.slice(5, 7))}月`;

export type CellTone = "shortage" | "empty" | "normal";

/** タイムラインの帯の色分け。在庫が持つ月数（月末在庫 ÷ 平均月販）で分ける。 */
export type CoverLevel = "plenty" | "some" | "low" | "empty" | "shortage";

/** タイムラインの帯の色（在庫が持つ月数）。凡例でも使う。 */
export const COVER_COLORS: Record<CoverLevel, string> = {
    plenty: "bg-stone-700",
    some: "bg-stone-400",
    low: "bg-stone-200",
    empty: "bg-amber-300",
    shortage: "bg-red-500",
};

export const COVER_LEGEND: { level: CoverLevel; label: string }[] = [
    { level: "plenty", label: "3か月以上" },
    { level: "some", label: "1〜3か月" },
    { level: "low", label: "1か月未満" },
    { level: "empty", label: "在庫0" },
    { level: "shortage", label: "足りない" },
];

export interface MonthCell {
    text: string;
    tone: CellTone;
    cover: CoverLevel;
}

export interface Sparkline {
    line: string;
    area: string;
    zeroY: number;
    checkX: number;
    inboundMarks: string[];
}

export interface SkuRow {
    sku: SkuTrend;
    stockText: string;
    cells: MonthCell[];
    /** 「在庫119・4.9か月分」（計算できないSKUは空） */
    coverText: string;
    /** 「1月から欠品」「12か月もつ」（計算できないSKUは null） */
    status: { label: string; isShortage: boolean } | null;
    sparkline: Sparkline | null;
}

export interface MonthInbound {
    total: number;
    provisional: number;
    text: string;
    label: string;
}

export interface ItemRows {
    item: ItemTrend;
    rows: SkuRow[];
    stockText: string;
    monthTexts: string[];
    monthTones: CellTone[];
    /** 月ごとの品番全体の入荷予定。入荷が無い月は null */
    monthInbounds: (MonthInbound | null)[];
    shortageCount: number;
    calculatedCount: number;
}

export interface TrendMonth {
    month: string;
    days: number;
}

function cellTone(shortfall: number, ending: number): CellTone {
    if (isShort(shortfall)) {
        return "shortage";
    }
    return Math.round(ending) === 0 ? "empty" : "normal";
}

function coverLevel(month: MonthTrend, averageDaily: number | null): CoverLevel {
    const tone = cellTone(month.shortfall, month.ending_stock);
    if (tone === "shortage") {
        return "shortage";
    }
    if (tone === "empty") {
        return "empty";
    }
    if (!averageDaily || averageDaily <= 0) {
        return "plenty";
    }
    const cover = month.ending_stock / (averageDaily * 30);
    return cover >= 3 ? "plenty" : cover >= 1 ? "some" : "low";
}

const SPARK_WIDTH = 240;
const SPARK_HEIGHT = 72;
const SPARK_PADDING = 6;

/** ミニグラフの線と面。値は月初の在庫と、各月末の「月末在庫 − 足りない数」（足りない月はマイナス）。 */
export function sparkline(sku: SkuTrend, checkMonthIndex: number): Sparkline | null {
    if (!sku.months || sku.opening_stock === null) {
        return null;
    }
    const values = [sku.opening_stock, ...sku.months.map((month) => month.ending_stock - month.shortfall)];
    const max = Math.max(1, ...values);
    const min = Math.min(0, ...values);
    const x = (index: number): number => 4 + (index * (SPARK_WIDTH - 8)) / sku.months!.length;
    const y = (value: number): number => SPARK_PADDING + ((max - value) * (SPARK_HEIGHT - SPARK_PADDING * 2)) / (max - min || 1);
    const zeroY = Number(y(0).toFixed(1));
    const line = `M ${values.map((value, index) => `${x(index).toFixed(1)} ${y(value).toFixed(1)}`).join(" L ")}`;
    const area = `${line} L ${x(values.length - 1).toFixed(1)} ${zeroY} L ${x(0).toFixed(1)} ${zeroY} Z`;
    const inboundMarks = sku.months.flatMap((month, index) => {
        if (Math.round(month.inbound + month.provisional_inbound) < 1) {
            return [];
        }
        const cx = x(index + 0.5);
        return [`M ${cx.toFixed(1)} ${SPARK_HEIGHT - 9} L ${(cx + 4).toFixed(1)} ${SPARK_HEIGHT - 2} L ${(cx - 4).toFixed(1)} ${SPARK_HEIGHT - 2} Z`];
    });
    return { line, area, zeroY, checkX: Number(x(checkMonthIndex + 1).toFixed(1)), inboundMarks };
}

function toSkuRow(sku: SkuTrend, checkMonthIndex: number): SkuRow {
    const months = sku.months ?? [];
    const calculated = sku.months !== null && sku.opening_stock !== null;
    return {
        sku,
        stockText: sku.opening_stock === null ? "—" : formatQuantity(sku.opening_stock),
        cells: months.map((month) => ({
            text: isShort(month.shortfall) ? `−${formatQuantity(month.shortfall)}` : formatQuantity(month.ending_stock),
            tone: cellTone(month.shortfall, month.ending_stock),
            cover: coverLevel(month, sku.average_daily),
        })),
        coverText: calculated && sku.average_daily && sku.average_daily > 0 ? `在庫${formatQuantity(sku.opening_stock!)}・${(sku.opening_stock! / (sku.average_daily * 30)).toFixed(1)}か月分` : "",
        status: calculated ? (sku.first_shortage_month ? { label: `${monthLabel(sku.first_shortage_month)}から欠品`, isShortage: true } : { label: "12か月もつ", isShortage: false }) : null,
        sparkline: calculated ? sparkline(sku, checkMonthIndex) : null,
    };
}

export function buildItemRows(items: ItemTrend[], months: TrendMonth[], checkMonthIndex: number): ItemRows[] {
    return items.map((item) => {
        const calculated = item.skus.filter((sku) => sku.months);
        const totals = months.map((_, index) => calculated.reduce((total, sku) => total + sku.months![index]!.ending_stock - sku.months![index]!.shortfall, 0));
        return {
            item,
            rows: item.skus.map((sku) => toSkuRow(sku, checkMonthIndex)),
            stockText: formatQuantity(item.skus.reduce((total, sku) => total + (sku.opening_stock ?? 0), 0)),
            monthTexts: totals.map((total) => (isShort(-total) ? `−${formatQuantity(-total)}` : formatQuantity(Math.max(0, total)))),
            monthTones: totals.map((total) => (isShort(-total) ? "shortage" : "normal")),
            monthInbounds: months.map((month, index) => {
                const total = calculated.reduce((sum, sku) => sum + sku.months![index]!.inbound + sku.months![index]!.provisional_inbound, 0);
                const provisional = calculated.reduce((sum, sku) => sum + sku.months![index]!.provisional_inbound, 0);
                if (Math.round(total) < 1) {
                    return null;
                }
                const provisionalText = Math.round(provisional) >= 1 ? `（うち仮の割り振り ${formatQuantity(provisional)}）` : "";
                return { total, provisional, text: `+${formatQuantity(total)}`, label: `${longMonthLabel(month.month)} 入荷予定 ${formatQuantity(total)}${provisionalText}` };
            }),
            shortageCount: item.skus.filter((sku) => sku.warning === "shortage").length,
            calculatedCount: calculated.length,
        };
    });
}

/** 月の見出しの上に出す年の段（「2026」が3か月、「2027」が9か月 など）。 */
export function yearSpans(months: TrendMonth[]): { year: string; span: number }[] {
    const spans: { year: string; span: number }[] = [];
    for (const month of months) {
        const year = month.month.slice(0, 4);
        const last = spans.at(-1);
        if (last && last.year === year) {
            last.span++;
        } else {
            spans.push({ year, span: 1 });
        }
    }
    return spans;
}

/** 月の欄・現在庫にマウスを乗せたときのツールチップの中身。 */
export type TrendTooltipContent = { kind: "month"; sku: SkuTrend; month: MonthTrend; isCheckMonth: boolean } | { kind: "stock"; sku: SkuTrend };
