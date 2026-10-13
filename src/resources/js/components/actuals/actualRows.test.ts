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
            ["26/1", "途中まで", false],
        ]);
        // 今月（今日）は計算した日から決める。期間の最後の列とは限らない
        expect(salesColumns({ ...sales, periods: [{ period: "2026-10", coverage: "partial" }] }).map((column) => [column.sub, column.current])).toEqual([["今月", true]]);

        const stock: StockActualsResponse["meta"] = {
            ...meta,
            scope_warehouses: [],
            dates: [
                { date: "2026-10-03", has_data: false, estimate: "estimated", estimate_base_date: "2026-10-02" },
                { date: "2026-10-04", has_data: false, estimate: "no_sales_data", estimate_base_date: "2026-10-02" },
                { date: "2026-10-05", has_data: true, estimate: null, estimate_base_date: null },
            ],
        };
        expect(stockColumns(stock).map((column) => [column.label, column.sub, column.muted])).toEqual([
            ["10/3", "推定", true],
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

    it("fills days without stock data with estimates, explains them and keeps the latest stock to the actual one", () => {
        const dates: StockActualsResponse["meta"]["dates"] = [
            { date: "2026-10-02", has_data: true, estimate: null, estimate_base_date: null },
            { date: "2026-10-03", has_data: false, estimate: "estimated", estimate_base_date: "2026-10-02" },
            { date: "2026-10-04", has_data: false, estimate: "estimated", estimate_base_date: "2026-10-02" },
            { date: "2026-10-05", has_data: false, estimate: "no_sales_data", estimate_base_date: "2026-10-02" },
        ];
        const stockItem = item([
            [8, null, null, null],
            [3, null, null, null],
            [null, null, null, null],
        ]);
        stockItem.skus[0]!.estimates = [null, { quantity: 6, base_quantity: 8, sold: 2 }, { quantity: 5, base_quantity: 8, sold: 3 }, null];
        stockItem.skus[1]!.estimates = [null, { quantity: 1, base_quantity: 3, sold: 2 }, { quantity: 0, base_quantity: 3, sold: 4 }, null];
        stockItem.skus[2]!.estimates = [null, null, null, null];

        const [rows] = buildActualRows([stockItem], "stock", "quantity", dates);

        const [first, second, unrecorded] = rows!.rows;
        expect(first!.values).toEqual([8, 6, 5, null]);
        expect(first!.estimated).toEqual([false, true, true, false]);
        expect(first!.cells.map((cell) => [cell.text, cell.estimated ?? false])).toEqual([
            ["8", false],
            ["6", true],
            ["5", true],
            ["—", false],
        ]);
        expect(first!.cells[1]!.title).toBe("推定：10/2(金)の在庫 8 − 10/2(金)の販売数 2");
        expect(first!.cells[2]!.title).toBe("推定：10/2(金)の在庫 8 − 10/2(金)〜10/3(土)の販売数 3");
        expect(first!.cells[3]!.title).toBe("販売実績を取り込んでいないため、推定できません");
        // 販売数が在庫を上回ったときは0として示す
        expect(second!.cells[2]!.tone).toBe("empty");
        expect(second!.cells[2]!.title).toContain("販売数が在庫を上回ったため0としています");
        expect(unrecorded!.cells[1]!.title).toBe("10/2(金)の在庫の記録が無いため、推定できません");
        // 最新は推定在庫ではなく、最後に在庫を取得した日の在庫
        expect(first!.summaryText).toBe("8");
        expect(rows!.summaryText).toBe("11");
        expect(rows!.totals.map((cell) => [cell.text, cell.estimated ?? false])).toEqual([
            ["11", false],
            ["7", true],
            ["5", true],
            ["—", false],
        ]);
    });

    it("draws the line to estimated stock dashed", () => {
        const line = actualSparkline([4, 3, 2, 5, null, 1], "stock", [false, true, true, false, false, true])!;

        expect(line.segments).toHaveLength(0);
        expect(line.estimatedSegments).toHaveLength(3);
        expect(line.points.map((point) => point.isEstimated)).toEqual([false, true, true, false, true]);
        expect(actualSparkline([4, 3, 2], "stock")!.estimatedSegments).toHaveLength(0);
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
