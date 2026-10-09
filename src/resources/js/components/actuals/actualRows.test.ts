import { describe, expect, it } from "vitest";
import { actualSparkline, buildActualRows, salesColumns, skuMallSeries, stockColumns } from "@/components/actuals/actualRows";
import type { ActualItem, SalesActualsResponse, StockActualsResponse } from "@/types/api";

const meta = { calculated_at: "2026-10-07T10:00:00+09:00", scope: "total" as const, scope_label: "全体", selection: null };

function item(quantities: (number | null)[][], notSold: boolean[] = []): ActualItem {
    return {
        item_no: "fl-01",
        brand: "B",
        category: "C",
        skus: quantities.map((values, index) => ({
            sku_id: index + 1,
            sku_code: `fl-01-1-${index}`,
            color_no: null,
            size: null,
            status: "active",
            status_label: "有効",
            not_sold: notSold[index] ?? false,
            quantities: values,
        })),
    };
}

describe("actualRows", () => {
    it("labels months with their coverage and days with the weekday or no data", () => {
        const sales: SalesActualsResponse["meta"] = {
            ...meta,
            unit: "month",
            from: "2025-10",
            to: "2026-01",
            periods: [
                { period: "2025-10", coverage: "none" },
                { period: "2025-11", coverage: "partial" },
                { period: "2025-12", coverage: "full" },
                { period: "2026-01", coverage: "partial" },
            ],
            channels: [],
        };
        expect(salesColumns(sales).map((column) => [column.label, column.sub, column.muted])).toEqual([
            ["25/10", "取込なし", true],
            ["25/11", "途中まで", false],
            ["25/12", "", false],
            ["26/1", "今月", false],
        ]);

        const stock: StockActualsResponse["meta"] = {
            ...meta,
            scope_warehouses: [],
            dates: [
                { date: "2026-10-04", has_data: false },
                { date: "2026-10-05", has_data: true },
            ],
        };
        expect(stockColumns(stock).map((column) => [column.label, column.sub, column.muted])).toEqual([
            ["10/4", "調査なし", true],
            ["10/5", "月", false],
        ]);
    });

    it("sums sales into the item row and the period total without turning missing months into 0", () => {
        const [rows] = buildActualRows(
            [
                item(
                    [
                        [null, 3, 5],
                        [null, 1, null],
                    ],
                    [false, false],
                ),
            ],
            "sales",
        );

        expect(rows!.rows[0]!.cells.map((cell) => cell.text)).toEqual(["—", "3", "5"]);
        expect(rows!.rows[0]!.summaryText).toBe("8");
        expect(rows!.totals.map((cell) => [cell.text, cell.tone])).toEqual([
            ["—", "none"],
            ["4", "normal"],
            ["5", "normal"],
        ]);
        expect(rows!.summaryText).toBe("9");
    });

    it("marks zero stock, shows the latest stock and leaves SKUs not sold on the mall out of the item totals", () => {
        const [rows] = buildActualRows(
            [
                item(
                    [
                        [10, 0, null],
                        [5, 5, 5],
                    ],
                    [false, true],
                ),
            ],
            "stock",
        );

        expect(rows!.rows[0]!.cells.map((cell) => cell.tone)).toEqual(["normal", "empty", "none"]);
        expect(rows!.rows[0]!.summaryText).toBe("0");
        expect(rows!.rows[1]!.summaryText).toBe("—");
        expect(rows!.totals.map((cell) => cell.text)).toEqual(["10", "0", "—"]);
    });

    it("breaks the line where there is no data and marks zero stock in red", () => {
        const line = actualSparkline([4, 2, null, 0, 1], "stock")!;

        expect(line.segments).toHaveLength(2);
        expect(line.points).toHaveLength(4);
        expect(line.points.filter((point) => point.isEmpty)).toHaveLength(1);
        expect(actualSparkline([0, 0], "sales")!.points.some((point) => point.isEmpty)).toBe(false);
        expect(actualSparkline([null, null], "stock")).toBeNull();
    });

    it("breaks a SKU down by mall for the tooltip in quantities or amounts", () => {
        const [sku] = item([[3]]).skus;
        sku!.channels = { boss: { quantities: [2], amounts: [11000] }, amazon: { quantities: [1], amounts: [5500] } };

        expect(skuMallSeries(sku!, "quantity").map((series) => [series.label, series.values])).toEqual([
            ["BOSS", [2]],
            ["Amazon", [1]],
        ]);
        expect(skuMallSeries(sku!, "amount").map((series) => series.values)).toEqual([[11000], [5500]]);
    });
});
