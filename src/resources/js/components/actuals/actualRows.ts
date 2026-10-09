import { formatQuantity, shortMonthLabel } from "@/components/inventoryTrend/trendRows";
import { formatThousandYen } from "@/components/salesTarget/format";
import type { ActualItem, ActualSku, SalesActualsResponse, StockActualsResponse } from "@/types/api";

/**
 * 実績（B-124）の表示用データ。販売実績（月ごと）と在庫実績（日ごと）で共通に使う。表とグラフの両方で使い、
 * データを読み込んだときに一度だけ作る。データの無い列（取り込んでいない月、在庫を取得していない日）は0とせず「—」にする（原則2）。
 */

/** 列（月か日）の見出し。muted はデータの無い列。 */
export interface ActualColumn {
    key: string;
    label: string;
    sub: string;
    muted: boolean;
}

/** 欄の見た目。empty は在庫0（在庫推移の在庫不足と同じ赤い札）、none はデータ無し。 */
export type ActualTone = "normal" | "empty" | "none";

export interface ActualCell {
    text: string;
    tone: ActualTone;
}

/** 数で見るか、金額（税込、千円）で見るか。金額は販売実績だけ。 */
export type ActualMeasure = "quantity" | "amount";

export interface ActualSkuRow {
    sku: ActualSku;
    /** 見ている方の列ごとの値（数か金額） */
    values: (number | null)[];
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

export function salesColumns(meta: SalesActualsResponse["meta"]): ActualColumn[] {
    const lastIndex = meta.periods.length - 1;
    const isDay = meta.unit === "day";
    return meta.periods.map((period, index) => ({
        key: period.period,
        label: isDay ? dayLabel(period.period) : shortMonthLabel(period.period),
        sub: index === lastIndex ? (isDay ? "今日" : "今月") : period.coverage === "none" ? "取込なし" : period.coverage === "partial" ? "途中まで" : isDay ? weekday(period.period) : "",
        muted: period.coverage === "none",
    }));
}

export function stockColumns(meta: StockActualsResponse["meta"]): ActualColumn[] {
    return meta.dates.map((date) => ({
        key: date.date,
        label: dayLabel(date.date),
        sub: date.has_data ? weekday(date.date) : "調査なし",
        muted: !date.has_data,
    }));
}

function cell(value: number | null, kind: "sales" | "stock", format: (value: number) => string): ActualCell {
    if (value === null) {
        return { text: "—", tone: "none" };
    }
    return { text: format(value), tone: kind === "stock" && Math.round(value) <= 0 ? "empty" : "normal" };
}

/** 販売実績は期間の合計、在庫実績は最後にデータのある日の在庫数。 */
function summary(values: (number | null)[], kind: "sales" | "stock", format: (value: number) => string): string {
    const known = values.filter((value): value is number => value !== null);
    if (known.length === 0) {
        return "—";
    }
    return format(kind === "sales" ? known.reduce((total, value) => total + value, 0) : known.at(-1)!);
}

export function buildActualRows(items: ActualItem[], kind: "sales" | "stock", measure: ActualMeasure = "quantity"): ActualItemRows[] {
    const valuesOf = (sku: ActualSku): (number | null)[] => (measure === "amount" ? (sku.amounts ?? []) : sku.quantities);
    const format = measure === "amount" ? formatThousandYen : formatQuantity;
    return items.map((item) => {
        const skus = item.skus.filter((sku) => !sku.not_sold);
        const columnCount = item.skus[0]?.quantities.length ?? 0;
        const totals = Array.from({ length: columnCount }, (_, index) => {
            const values = skus.map((sku) => valuesOf(sku)[index] ?? null).filter((value): value is number => value !== null);
            return values.length === 0 ? null : values.reduce((total, value) => total + value, 0);
        });
        return {
            item,
            rows: item.skus.map((sku) => ({
                sku,
                values: valuesOf(sku),
                cells: valuesOf(sku).map((value) => cell(value, kind, format)),
                summaryText: sku.not_sold ? "—" : summary(valuesOf(sku), kind, format),
            })),
            totalValues: totals,
            totals: totals.map((value) => cell(value, kind, format)),
            summaryText: summary(totals, kind, format),
        };
    });
}

const SPARK_WIDTH = 240;
const SPARK_HEIGHT = 56;
const SPARK_PADDING = 5;

/**
 * グラフの線と点。データの無い列で線を切る（0として結ばない）。前後にデータの無い1点も見えるよう、点も打つ。
 * 在庫実績で在庫0の点は、在庫推移の在庫不足と同じく赤く示す（isEmpty）。
 */
export function actualSparkline(values: (number | null)[], kind: "sales" | "stock"): { segments: string[]; points: { x: number; y: number; isEmpty: boolean }[] } | null {
    const known = values.filter((value): value is number => value !== null);
    if (known.length === 0) {
        return null;
    }
    const max = Math.max(1, ...known);
    const x = (index: number): number => SPARK_PADDING + (index * (SPARK_WIDTH - SPARK_PADDING * 2)) / Math.max(1, values.length - 1);
    const y = (value: number): number => SPARK_PADDING + ((max - value) * (SPARK_HEIGHT - SPARK_PADDING * 2)) / max;
    const segments: string[] = [];
    let current: string[] = [];
    values.forEach((value, index) => {
        if (value === null) {
            if (current.length > 1) {
                segments.push(current.join(" "));
            }
            current = [];
            return;
        }
        current.push(`${current.length === 0 ? "M" : "L"} ${x(index).toFixed(1)} ${y(value).toFixed(1)}`);
    });
    if (current.length > 1) {
        segments.push(current.join(" "));
    }
    const points = values.flatMap((value, index) => (value === null ? [] : [{ x: Number(x(index).toFixed(1)), y: Number(y(value).toFixed(1)), isEmpty: kind === "stock" && Math.round(value) <= 0 }]));
    return { segments, points };
}

/** グラフの系列（積み上げの1段）。values は列ごとの値で、データの無い列は null。 */
export interface ChartSeries {
    key: string;
    label: string;
    color: string;
    values: (number | null)[];
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

/** 品番の販売数（金額）を、モールごとの積み上げにする。選んだモールだけのときは1段。 */
export function mallSeries(item: ActualItem, measure: ActualMeasure, length: number): ChartSeries[] {
    const skus = item.skus.filter((sku) => !sku.not_sold);
    return MALL_SERIES.filter((mall) => skus.some((sku) => sku.channels?.[mall.key])).map((mall) => ({
        ...mall,
        values: sumColumns(
            skus.map((sku) => (measure === "amount" ? sku.channels?.[mall.key]?.amounts : sku.channels?.[mall.key]?.quantities) ?? []),
            length,
        ),
    }));
}

/** SKUの販売数（金額）の、モールごとの値（SKU別売上の折れ線に使う）。 */
export function skuMallSeries(sku: ActualSku, measure: ActualMeasure): ChartSeries[] {
    return MALL_SERIES.filter((mall) => sku.channels?.[mall.key]).map((mall) => ({
        ...mall,
        values: (measure === "amount" ? sku.channels?.[mall.key]?.amounts : sku.channels?.[mall.key]?.quantities) ?? [],
    }));
}

const compactFormat = new Intl.NumberFormat("ja-JP", { notation: "compact", maximumFractionDigits: 1 });

/** グラフの値・縦軸の表示（数量は個、金額は円。縦軸の金額は「50万」のように短くする）。 */
export function chartFormats(measure: ActualMeasure): { formatValue: (value: number) => string; formatAxis: (value: number) => string; axisUnit: string } {
    return measure === "amount"
        ? { formatValue: (value) => `${formatQuantity(value)}円`, formatAxis: (value) => compactFormat.format(value), axisUnit: "円" }
        : { formatValue: (value) => `${formatQuantity(value)}個`, formatAxis: (value) => formatQuantity(value), axisUnit: "個" };
}
