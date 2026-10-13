import { STORE_COLORS, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import { formatThousandYen } from "@/components/salesTarget/format";
import type { StoreSales } from "@/types/api";

/**
 * 店舗売上（B-126）の表の行。店舗ごとの行と、店舗の合計の行を作る。販売実績を取り込んでいない期間は0とせず「—」にする（原則2）。
 * 合計は、販売実績を取り込んだ店舗だけを足し、取り込んでいない店舗があればそのことを示す（K-088）。
 */

export interface StoreCell {
    text: string;
    tone: "normal" | "none";
    /** 販売実績を取り込んでいない店舗を除いた合計（除いた店舗はツールチップで示す） */
    excludes?: boolean;
}

export interface StoreSalesRow {
    key: string;
    label: string;
    /** 店舗の色（合計は null） */
    color: string | null;
    /** 列ごとの値（グラフ・期間計に使う） */
    values: (number | null)[];
    cells: StoreCell[];
    summaryText: string;
    /** 期間計の、店舗の合計に対する割合 */
    shareText: string;
}

const sum = (values: (number | null)[]): number | null => {
    const known = values.filter((value): value is number => value !== null);
    return known.length === 0 ? null : known.reduce((total, value) => total + value, 0);
};

export function buildStoreRows(stores: StoreSales[], measure: ActualMeasure): { total: StoreSalesRow; stores: StoreSalesRow[] } {
    const format = measure === "amount" ? formatThousandYen : formatQuantity;
    const valuesOf = (store: StoreSales): (number | null)[] => (measure === "amount" ? store.amounts : store.quantities);
    const columnCount = stores[0]?.quantities.length ?? 0;
    const totalValues = Array.from({ length: columnCount }, (_, index) => sum(stores.map((store) => valuesOf(store)[index] ?? null)));
    const grandTotal = sum(totalValues);
    const share = (value: number | null): string => (value === null || grandTotal === null || grandTotal <= 0 ? "—" : `${((value / grandTotal) * 100).toFixed(1)}%`);
    const text = (value: number | null): string => (value === null ? "—" : format(value));

    const storeRows = stores.map((store): StoreSalesRow => {
        const values = valuesOf(store);
        const total = sum(values);
        return {
            key: store.mall,
            label: store.label,
            color: STORE_COLORS[store.mall],
            values,
            cells: values.map((value) => (value === null ? { text: "—", tone: "none" } : { text: format(value), tone: "normal" })),
            summaryText: text(total),
            shareText: share(total),
        };
    });

    return {
        total: {
            key: "total",
            label: "合計",
            color: null,
            values: totalValues,
            cells: totalValues.map((value, index) => {
                if (value === null) {
                    return { text: "—", tone: "none" };
                }
                return { text: format(value), tone: "normal", excludes: stores.some((store) => (valuesOf(store)[index] ?? null) === null) };
            }),
            summaryText: text(grandTotal),
            shareText: grandTotal === null ? "—" : "100%",
        },
        stores: storeRows,
    };
}
