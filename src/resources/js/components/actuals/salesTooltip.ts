import type { ActualColumn, ChartSeries } from "@/components/actuals/actualRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import type { SalesUnit, StockoutPeriod } from "@/types/api";

/**
 * 販売実績のツールチップの中身（B-127・B-126）。グラフ（SalesChart）と店舗売上の表で共通に使う。ある月・日の合計と系列（モール・店舗）ごとの値、
 * 前月比（日ごとは前日比）と前年比（K-082）、SKU別売上の折れ線では欠品日数（K-084）を出す。比べる期間の販売実績が無い・0のときは「—」。
 */

export interface SalesTooltipRow {
    key: string;
    label: string;
    color: string;
    value: number | null;
    previous: string;
    lastYear: string;
    /** 合計に対する割合（share を求めたときだけ） */
    share?: string;
}

export interface SalesTooltip {
    column: ActualColumn;
    /** 系列の合計。どの系列も販売実績を取り込んでいなければ null */
    total: number | null;
    previous: string;
    lastYear: string;
    rows: SalesTooltipRow[];
    /** 販売実績を取り込んでいないため、合計に含めていない系列 */
    missing: string[];
    stockouts: { key: string; label: string; value: string }[];
}

/** 比べる期間に対する割合（「112%」）。比べる値が無い・0なら「—」。 */
export function ratio(value: number | null, base: number | null): string {
    return value === null || base === null || base <= 0 ? "—" : `${formatQuantity(Math.round((value / base) * 100))}%`;
}

/** 列の合計（表示している系列）。データの無い列は null。 */
export function seriesTotal(series: ChartSeries[], index: number): number | null {
    const values = series.map((item) => item.values[index] ?? null);
    return values.every((value) => value === null) ? null : values.reduce<number>((total, value) => total + Math.max(0, value ?? 0), 0);
}

/** 比べる期間の値の合計（表示している系列）。値のある系列のどれかが比べられなければ null。 */
function comparisonTotal(series: ChartSeries[], index: number, key: "previous" | "lastYear"): number | null {
    let total = 0;
    for (const item of series) {
        if ((item.values[index] ?? null) === null) {
            continue;
        }
        const value = item[key]?.[index] ?? null;
        if (value === null) {
            return null;
        }
        total += Math.max(0, value);
    }
    return total;
}

function stockoutLabel(period: StockoutPeriod | undefined): string {
    if (period?.days === null || period === undefined) {
        return "—";
    }
    return period.known_days < period.period_days ? `${period.days}日（確認${period.known_days}/${period.period_days}日）` : `${period.days}日`;
}

/**
 * kind が line（折れ線）なら0の系列も出し（2本のどちらが売れていないかも分かるように）、欠品日数を添える。bar なら値のある系列だけを多い順に出す。
 * withShare なら系列ごとに合計に対する割合を添える。
 */
export function buildSalesTooltip(series: ChartSeries[], column: ActualColumn, index: number, options: { kind: "bar" | "line"; unit: SalesUnit; withShare?: boolean }): SalesTooltip {
    const total = seriesTotal(series, index);
    const rows = series
        .map((item): SalesTooltipRow => {
            const value = item.values[index] ?? null;
            return {
                key: item.key,
                label: item.label,
                color: item.color,
                value,
                previous: ratio(value, item.previous?.[index] ?? null),
                lastYear: ratio(value, item.lastYear?.[index] ?? null),
                ...(options.withShare ? { share: value === null || total === null || total <= 0 ? "—" : `${((Math.max(0, value) / total) * 100).toFixed(1)}%` } : {}),
            };
        })
        .filter((row) => row.value !== null && (options.kind === "line" || row.value > 0))
        .sort((a, b) => b.value! - a.value!);
    const stockouts = options.kind === "line" && options.unit === "month" ? series.map((item) => ({ key: item.key, label: item.label, value: stockoutLabel(item.stockouts?.[index]) })) : [];
    return {
        column,
        total,
        previous: ratio(total, comparisonTotal(series, index, "previous")),
        lastYear: ratio(total, comparisonTotal(series, index, "lastYear")),
        rows,
        missing: total === null ? [] : series.filter((item) => (item.values[index] ?? null) === null).map((item) => item.label),
        stockouts,
    };
}
