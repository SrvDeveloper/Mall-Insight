import { formatLargeYen, formatYen } from "@/components/salesTarget/format";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import type { ActualMeasure } from "@/components/actuals/actualRows";
import type { ActualItem, ActualSku } from "@/types/api";

/**
 * SKU別売上（B-127、K-081）の表示用データ。SKUを期間計の多い順に並べ、モール（BOSS・Amazon）ごとの内訳、全体に対する割合、
 * 一番売れた月（日）、カラー×度数の表を作る。数量と金額のどちらで見るかは measure で選ぶ。
 */

export type Mall = "boss" | "amazon";

export interface SkuSalesRow {
    sku: ActualSku;
    /** 期間ごとの値（数量か金額）。データの無い期間は null */
    values: (number | null)[];
    malls: Record<Mall, (number | null)[]>;
    sum: number;
    mallSums: Record<Mall, number>;
    /** 品番の期間計に対する割合（0～1） */
    share: number;
    /** 一番売れたSKUを1としたときの、BOSS・Amazonの棒の長さ（0～1） */
    bars: Record<Mall, number>;
    rank: number | null;
}

export interface ColorSizeMatrix {
    colors: string[];
    sizes: { size: string; label: string }[];
    cells: Record<string, SkuSalesRow | undefined>;
    colorSums: Record<string, Record<Mall, number>>;
    sizeSums: Record<string, Record<Mall, number>>;
}

export interface SkuSalesModel {
    rows: SkuSalesRow[];
    malls: Mall[];
    sum: number;
    mallSums: Record<Mall, number>;
    /** 一番売れた期間の位置と値。売れていなければ null */
    best: { index: number; value: number } | null;
    /** 度数の無い品番（サングラスなど）は null（カラー×度数の表を出さない） */
    matrix: ColorSizeMatrix | null;
}

const MALLS: Mall[] = ["boss", "amazon"];

const sum = (values: (number | null)[]): number => values.reduce<number>((total, value) => total + (value ?? 0), 0);

/** 度数（TQサイズ×10）を「+1.0」の形にする。 */
export const sizeLabel = (size: string): string => `+${(Number(size) / 10).toFixed(1)}`;

/** 度数のあるSKUか（度数の無いSKUはサイズが 0 か空）。 */
const hasSize = (sku: ActualSku): boolean => sku.size !== null && sku.size !== "" && Number(sku.size) > 0;

export function buildSkuSales(item: ActualItem, measure: ActualMeasure): SkuSalesModel {
    const valuesOf = (sku: ActualSku): (number | null)[] => (measure === "amount" ? (sku.amounts ?? []) : sku.quantities);
    const mallValues = (sku: ActualSku, mall: Mall): (number | null)[] => (measure === "amount" ? sku.channels?.[mall]?.amounts : sku.channels?.[mall]?.quantities) ?? [];
    const malls = MALLS.filter((mall) => item.skus.some((sku) => sku.channels?.[mall]));

    const rows = item.skus.map((sku) => {
        const values = valuesOf(sku);
        const mallLists = { boss: mallValues(sku, "boss"), amazon: mallValues(sku, "amazon") };
        return { sku, values, malls: mallLists, sum: sum(values), mallSums: { boss: sum(mallLists.boss), amazon: sum(mallLists.amazon) } };
    });
    const sold = rows.filter((row) => !row.sku.not_sold);
    const total = sold.reduce((value, row) => value + row.sum, 0);
    const top = Math.max(0, ...sold.map((row) => row.sum));
    const ranked = [...sold].sort((a, b) => b.sum - a.sum || item.skus.indexOf(a.sku) - item.skus.indexOf(b.sku));
    const ordered: SkuSalesRow[] = [...ranked, ...rows.filter((row) => row.sku.not_sold)].map((row) => ({
        ...row,
        share: total > 0 && !row.sku.not_sold ? row.sum / total : 0,
        bars: { boss: top > 0 ? row.mallSums.boss / top : 0, amazon: top > 0 ? row.mallSums.amazon / top : 0 },
        rank: row.sku.not_sold ? null : ranked.indexOf(row) + 1,
    }));

    const length = item.skus[0]?.quantities.length ?? 0;
    const periodTotals = Array.from({ length }, (_, index) => sold.reduce((value, row) => value + (row.values[index] ?? 0), 0));
    const bestValue = Math.max(0, ...periodTotals);
    const best = bestValue > 0 ? { index: periodTotals.indexOf(bestValue), value: bestValue } : null;

    return {
        rows: ordered,
        malls,
        sum: total,
        mallSums: { boss: sold.reduce((value, row) => value + row.mallSums.boss, 0), amazon: sold.reduce((value, row) => value + row.mallSums.amazon, 0) },
        best,
        matrix: item.skus.some(hasSize) ? buildMatrix(ordered.filter((row) => hasSize(row.sku))) : null,
    };
}

function buildMatrix(rows: SkuSalesRow[]): ColorSizeMatrix {
    const numeric = (a: string, b: string): number => Number(a) - Number(b) || a.localeCompare(b);
    const colors = [...new Set(rows.map((row) => row.sku.color_no ?? "—"))].sort(numeric);
    const sizes = [...new Set(rows.map((row) => row.sku.size!))].sort(numeric);
    const cells: Record<string, SkuSalesRow | undefined> = {};
    const colorSums: Record<string, Record<Mall, number>> = {};
    const sizeSums: Record<string, Record<Mall, number>> = {};
    for (const row of rows) {
        const color = row.sku.color_no ?? "—";
        cells[`${color}|${row.sku.size}`] = row;
        if (row.sku.not_sold) {
            continue;
        }
        for (const [key, sums] of [
            [color, colorSums],
            [row.sku.size!, sizeSums],
        ] as const) {
            sums[key] ??= { boss: 0, amazon: 0 };
            sums[key].boss += row.mallSums.boss;
            sums[key].amazon += row.mallSums.amazon;
        }
    }
    return { colors, sizes: sizes.map((size) => ({ size, label: sizeLabel(size) })), cells, colorSums, sizeSums };
}

/** 一覧や合計の値の表示（数量は個数、金額は「1,234万円」）。 */
export const formatTotal = (value: number, measure: ActualMeasure): string => (measure === "amount" ? formatLargeYen(value) : formatQuantity(value));

/** 表の欄の値の表示（数量は個数、金額は円）。 */
export const formatCell = (value: number, measure: ActualMeasure): string => (measure === "amount" ? formatYen(value) : formatQuantity(value));

/** 割合の表示（「12.3%」）。 */
export const formatShare = (value: number): string => `${(value * 100).toFixed(1)}%`;
