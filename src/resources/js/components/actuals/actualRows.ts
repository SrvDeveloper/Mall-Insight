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
    /** 列ごとの品番の合計。SKUのどれにもデータが無い列は「—」 */
    totals: ActualCell[];
    summaryText: string;
}

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];

export function salesColumns(meta: SalesActualsResponse["meta"]): ActualColumn[] {
    const lastIndex = meta.months.length - 1;
    return meta.months.map((month, index) => ({
        key: month.month,
        label: shortMonthLabel(month.month),
        sub: index === lastIndex ? "今月" : month.coverage === "none" ? "取込なし" : month.coverage === "partial" ? "途中まで" : "",
        muted: month.coverage === "none",
    }));
}

export function stockColumns(meta: StockActualsResponse["meta"]): ActualColumn[] {
    return meta.dates.map((date) => ({
        key: date.date,
        label: `${Number(date.date.slice(5, 7))}/${Number(date.date.slice(8, 10))}`,
        sub: date.has_data ? WEEKDAYS[new Date(`${date.date}T00:00:00`).getDay()]! : "調査なし",
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
