import { formatQuantity, shortMonthLabel } from "@/components/inventoryTrend/trendRows";
import { formatThousandYen } from "@/components/salesTarget/format";
import type { ActualItem, ActualSku, SalesActualsResponse, StockActualDate, StockActualsResponse, StockEstimate, StockoutPeriod } from "@/types/api";

/**
 * 実績（B-124）の表示用データ。販売実績（月ごと）と在庫実績（日ごと）で共通に使う。表とグラフの両方で使い、
 * データを読み込んだときに一度だけ作る。データの無い列（取り込んでいない月、在庫を取得していない日）は0とせず「—」にする（原則2）。
 * 在庫を取得していない日に推定在庫があれば、実際の在庫と見分けられるようにして出し、推定の根拠を添える（K-087）。
 */

/** 列（月か日）の見出し。muted はデータの無い列、current は今月（今日）の列。 */
export interface ActualColumn {
    key: string;
    label: string;
    sub: string;
    muted: boolean;
    current?: boolean;
}

/** 欄の見た目。empty は在庫0（在庫推移の在庫不足と同じ赤い札）、none はデータ無し。 */
export type ActualTone = "normal" | "empty" | "none";

export interface ActualCell {
    text: string;
    tone: ActualTone;
    /** 推定在庫（在庫実績で在庫を取得していない日、K-087） */
    estimated?: boolean;
    /** ホバーで出す説明（推定の根拠や、推定できない理由） */
    title?: string;
}

/** 数で見るか、金額（税込、千円）で見るか。金額は販売実績だけ。 */
export type ActualMeasure = "quantity" | "amount";

export interface ActualSkuRow {
    sku: ActualSku;
    /** 見ている方の列ごとの値（数か金額）。在庫実績では推定在庫を含む */
    values: (number | null)[];
    /** 列ごとに、値が推定在庫か */
    estimated: boolean[];
    cells: ActualCell[];
    /** 列の合計（販売数）か、最後にデータのある日の在庫数 */
    summaryText: string;
}

export interface ActualItemRows {
    item: ActualItem;
    rows: ActualSkuRow[];
    /** 列ごとの品番の合計の値（グラフに使う）。SKUのどれにもデータが無い列は null */
    totalValues: (number | null)[];
    /** 列ごとの品番の合計。SKUのどれにもデータが無い列は「—」 */
    totals: ActualCell[];
    summaryText: string;
}

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];

const dayLabel = (date: string): string => `${Number(date.slice(5, 7))}/${Number(date.slice(8, 10))}`;
const weekday = (date: string): string => WEEKDAYS[new Date(`${date}T00:00:00`).getDay()]!;
const dayWithWeekday = (date: string): string => `${dayLabel(date)}(${weekday(date)})`;

/** 日付の前日（YYYY-MM-DD）。 */
function previousDay(date: string): string {
    const day = new Date(`${date}T00:00:00`);
    day.setDate(day.getDate() - 1);
    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, "0")}-${String(day.getDate()).padStart(2, "0")}`;
}

/** 推定在庫の根拠（例：「推定：10/9(金)の在庫 12 − 10/9(金)〜10/11(日)の販売数 3」）。販売数が在庫を上回ったときは0にしたことを添える。 */
function estimateTitle(estimate: StockEstimate, date: StockActualDate): string {
    const base = date.estimate_base_date!;
    const last = previousDay(date.date);
    const period = base === last ? dayWithWeekday(base) : `${dayWithWeekday(base)}〜${dayWithWeekday(last)}`;
    const note = estimate.base_quantity - estimate.sold < 0 ? "（販売数が在庫を上回ったため0としています）" : "";
    return `推定：${dayWithWeekday(base)}の在庫 ${formatQuantity(estimate.base_quantity)} − ${period}の販売数 ${formatQuantity(estimate.sold)}${note}`;
}

/** 在庫を取得していない日に推定在庫を出せないときの理由。 */
function noEstimateTitle(date: StockActualDate): string | undefined {
    switch (date.estimate) {
        case "no_base":
            return "在庫の記録より前のため、推定できません";
        case "no_sales_data":
            return "販売実績を取り込んでいないため、推定できません";
        case "estimated":
            return `${dayWithWeekday(date.estimate_base_date!)}の在庫の記録が無いため、推定できません`;
        default:
            return undefined;
    }
}

export function salesColumns(meta: SalesActualsResponse["meta"]): ActualColumn[] {
    const isDay = meta.unit === "day";
    // 今月（今日）は計算した日時から決める（期間を指定すると、最後の列が今月とは限らない）
    const today = meta.calculated_at.slice(0, 10);
    const currentPeriod = isDay ? today : today.slice(0, 7);
    return meta.periods.map((period) => {
        const current = period.period === currentPeriod;
        return {
            key: period.period,
            label: isDay ? dayLabel(period.period) : shortMonthLabel(period.period),
            sub: current ? (isDay ? "今日" : "今月") : period.coverage === "none" ? "取込なし" : period.coverage === "partial" ? "途中まで" : isDay ? weekday(period.period) : "",
            muted: period.coverage === "none",
            current,
        };
    });
}

export function stockColumns(meta: StockActualsResponse["meta"]): ActualColumn[] {
    return meta.dates.map((date) => ({
        key: date.date,
        label: dayLabel(date.date),
        sub: date.has_data ? weekday(date.date) : date.estimate === "estimated" ? "推定" : "調査なし",
        muted: !date.has_data,
    }));
}

function cell(value: number | null, kind: "sales" | "stock", format: (value: number) => string): ActualCell {
    if (value === null) {
        return { text: "—", tone: "none" };
    }
    return { text: format(value), tone: kind === "stock" && Math.round(value) <= 0 ? "empty" : "normal" };
}

/** 販売実績は期間の合計、在庫実績は最後にデータのある日の在庫数（推定在庫は使わない）。 */
function summary(values: (number | null)[], kind: "sales" | "stock", format: (value: number) => string): string {
    const known = values.filter((value): value is number => value !== null);
    if (known.length === 0) {
        return "—";
    }
    return format(kind === "sales" ? known.reduce((total, value) => total + value, 0) : known.at(-1)!);
}

/**
 * stockDates（在庫実績の meta.dates）を渡すと、在庫を取得していない日に推定在庫を出す（K-087）。品番の合計は、推定在庫を含む列を推定として示す。
 */
export function buildActualRows(items: ActualItem[], kind: "sales" | "stock", measure: ActualMeasure = "quantity", stockDates: StockActualDate[] = []): ActualItemRows[] {
    const actualsOf = (sku: ActualSku): (number | null)[] => (measure === "amount" ? (sku.amounts ?? []) : sku.quantities);
    const estimatesOf = (sku: ActualSku): (StockEstimate | null)[] => (kind === "stock" ? actualsOf(sku).map((value, index) => (value === null ? (sku.estimates?.[index] ?? null) : null)) : []);
    const format = measure === "amount" ? formatThousandYen : formatQuantity;
    return items.map((item) => {
        const rows: ActualSkuRow[] = item.skus.map((sku) => {
            const actuals = actualsOf(sku);
            const estimates = estimatesOf(sku);
            const values = actuals.map((value, index) => value ?? estimates[index]?.quantity ?? null);
            return {
                sku,
                values,
                estimated: actuals.map((_, index) => estimates[index] != null),
                cells: values.map((value, index) => {
                    const estimate = estimates[index];
                    const date = stockDates[index];
                    if (estimate && date) {
                        return { ...cell(value, kind, format), estimated: true, title: estimateTitle(estimate, date) };
                    }
                    return value === null && date && !date.has_data && !sku.not_sold ? { ...cell(value, kind, format), title: noEstimateTitle(date) } : cell(value, kind, format);
                }),
                summaryText: sku.not_sold ? "—" : summary(actuals, kind, format),
            };
        });
        const sold = rows.filter((row) => !row.sku.not_sold);
        const columnCount = item.skus[0]?.quantities.length ?? 0;
        const totals = Array.from({ length: columnCount }, (_, index) => {
            const values = sold.map((row) => row.values[index] ?? null).filter((value): value is number => value !== null);
            return values.length === 0 ? null : values.reduce((total, value) => total + value, 0);
        });
        const actualTotals = Array.from({ length: columnCount }, (_, index) => {
            const values = sold.map((row) => actualsOf(row.sku)[index] ?? null).filter((value): value is number => value !== null);
            return values.length === 0 ? null : values.reduce((total, value) => total + value, 0);
        });
        return {
            item,
            rows,
            totalValues: totals,
            totals: totals.map((value, index) =>
                sold.some((row) => row.estimated[index]) ? { ...cell(value, kind, format), estimated: true, title: "推定在庫を含む合計です" } : cell(value, kind, format),
            ),
            summaryText: summary(actualTotals, kind, format),
        };
    });
}

const SPARK_WIDTH = 240;
const SPARK_HEIGHT = 56;
const SPARK_PADDING = 5;

/**
 * グラフの線と点。データの無い列で線を切る（0として結ばない）。前後にデータの無い1点も見えるよう、点も打つ。
 * 在庫実績で在庫0の点は、在庫推移の在庫不足と同じく赤く示す（isEmpty）。推定在庫（estimated）へつなぐ線は estimatedSegments に分け、
 * 点線で描く（K-087）。
 */
export function actualSparkline(
    values: (number | null)[],
    kind: "sales" | "stock",
    estimated: boolean[] = [],
): { segments: string[]; estimatedSegments: string[]; points: { x: number; y: number; isEmpty: boolean; isEstimated: boolean }[] } | null {
    const known = values.filter((value): value is number => value !== null);
    if (known.length === 0) {
        return null;
    }
    const max = Math.max(1, ...known);
    const x = (index: number): number => SPARK_PADDING + (index * (SPARK_WIDTH - SPARK_PADDING * 2)) / Math.max(1, values.length - 1);
    const y = (value: number): number => SPARK_PADDING + ((max - value) * (SPARK_HEIGHT - SPARK_PADDING * 2)) / max;
    const at = (index: number, value: number): string => `${x(index).toFixed(1)} ${y(value).toFixed(1)}`;
    const segments: string[] = [];
    const estimatedSegments: string[] = [];
    let current: string[] = [];
    const flush = (): void => {
        if (current.length > 1) {
            segments.push(current.join(" "));
        }
        current = [];
    };
    values.forEach((value, index) => {
        if (value === null) {
            flush();
            return;
        }
        const previous = index > 0 ? (values[index - 1] ?? null) : null;
        const isEstimated = estimated[index] ?? false;
        if (previous !== null && (isEstimated || (estimated[index - 1] ?? false))) {
            estimatedSegments.push(`M ${at(index - 1, previous)} L ${at(index, value)}`);
        }
        if (isEstimated) {
            flush();
            return;
        }
        current.push(`${current.length === 0 ? "M" : "L"} ${at(index, value)}`);
    });
    flush();
    const points = values.flatMap((value, index) =>
        value === null ? [] : [{ x: Number(x(index).toFixed(1)), y: Number(y(value).toFixed(1)), isEmpty: kind === "stock" && Math.round(value) <= 0, isEstimated: estimated[index] ?? false }],
    );
    return { segments, estimatedSegments, points };
}

/**
 * グラフの系列（積み上げの1段）。values は列ごとの値で、データの無い列は null。
 * previous・lastYear は前の期間（前月・前日）と前年の同じ期間の値で、ツールチップの前月比・前年比に使う（K-082）。比べられない列は null。
 */
export interface ChartSeries {
    key: string;
    label: string;
    color: string;
    values: (number | null)[];
    previous?: (number | null)[];
    lastYear?: (number | null)[];
    stockouts?: StockoutPeriod[];
}

/**
 * グラフの色（K-079）。グラフの指針の検証済みの並び（青・橙）を、この順で使う。赤は在庫不足（K-074）に使っているため使わない。
 */
export const SERIES_COLORS = ["#2a78d6", "#eb6834"];

/** モールの色は、どの画面でも BOSS が青、Amazon が橙（色はモールに付け、並び順に付けない）。 */
export const MALL_SERIES: { key: "boss" | "amazon"; label: string; color: string }[] = [
    { key: "boss", label: "BOSS", color: SERIES_COLORS[0]! },
    { key: "amazon", label: "Amazon", color: SERIES_COLORS[1]! },
];

/** 列ごとに値を足す。どの値もデータ無しの列は null。 */
function sumColumns(lists: (number | null)[][], length: number): (number | null)[] {
    return Array.from({ length }, (_, index) => {
        const values = lists.map((list) => list[index] ?? null).filter((value): value is number => value !== null);
        return values.length === 0 ? null : values.reduce((total, value) => total + value, 0);
    });
}

/** SKUのモールの値（今の期間、前の期間、前年の同じ期間）。 */
function channelValues(sku: ActualSku, mall: "boss" | "amazon", measure: ActualMeasure, source: "current" | "previous" | "last_year"): (number | null)[] {
    const channel = sku.channels?.[mall];
    const values = source === "current" ? channel : channel?.[source];
    return (measure === "amount" ? values?.amounts : values?.quantities) ?? [];
}

/** 品番の販売数（金額）を、モールごとの積み上げにする。選んだモールだけのときは1段。 */
export function mallSeries(item: ActualItem, measure: ActualMeasure, length: number): ChartSeries[] {
    const skus = item.skus.filter((sku) => !sku.not_sold);
    const sumOf = (mall: "boss" | "amazon", source: "current" | "previous" | "last_year") =>
        sumColumns(
            skus.map((sku) => channelValues(sku, mall, measure, source)),
            length,
        );
    return MALL_SERIES.filter((mall) => skus.some((sku) => sku.channels?.[mall.key])).map((mall) => ({
        ...mall,
        values: sumOf(mall.key, "current"),
        previous: sumOf(mall.key, "previous"),
        lastYear: sumOf(mall.key, "last_year"),
    }));
}

/** SKUの販売数（金額）の、モールごとの値（SKU別売上の折れ線に使う）。 */
export function skuMallSeries(sku: ActualSku, measure: ActualMeasure): ChartSeries[] {
    return MALL_SERIES.filter((mall) => sku.channels?.[mall.key]).map((mall) => ({
        ...mall,
        values: channelValues(sku, mall.key, measure, "current"),
        previous: channelValues(sku, mall.key, measure, "previous"),
        lastYear: channelValues(sku, mall.key, measure, "last_year"),
        stockouts: sku.channels?.[mall.key]?.stockouts,
    }));
}

const compactFormat = new Intl.NumberFormat("ja-JP", { notation: "compact", maximumFractionDigits: 1 });

/** グラフの値・縦軸の表示（数量は個、金額は円。縦軸の金額は「50万」のように短くする）。 */
export function chartFormats(measure: ActualMeasure): { formatValue: (value: number) => string; formatAxis: (value: number) => string; axisUnit: string } {
    return measure === "amount"
        ? { formatValue: (value) => `${formatQuantity(value)}円`, formatAxis: (value) => compactFormat.format(value), axisUnit: "円" }
        : { formatValue: (value) => `${formatQuantity(value)}個`, formatAxis: (value) => formatQuantity(value), axisUnit: "個" };
}
