import { describe, expect, it } from "vitest";
import { buildItemRows, sparkline, yearSpans } from "@/components/inventoryTrend/trendRows";
import type { ItemTrend, MonthTrend, SkuTrend } from "@/types/api";

const MONTHS = ["2026-10", "2026-11", "2026-12", "2027-01"];

function month(index: number, ending: number, shortfall = 0, inbound = 0): MonthTrend {
    return { month: MONTHS[index]!, days: 30, opening_stock: 0, demand: 30, inbound, provisional_inbound: 0, ending_stock: ending, shortfall };
}

function sku(overrides: Partial<SkuTrend> = {}): SkuTrend {
    return {
        sku_id: 7,
        sku_code: "fl-01-1-10",
        status: "calculated",
        status_label: "計算済み",
        opening_stock: 90,
        stock_by_warehouse: [],
        average_daily: 1,
        is_partial_forecast: false,
        forecast_reason_label: null,
        warning: "shortage",
        warning_label: "欠品警告",
        first_shortage_month: "2027-01",
        months: [month(0, 60), month(1, 30, 0, 10), month(2, 0), month(3, 0, 30)],
        ...overrides,
    };
}

describe("trendRows", () => {
    it("groups months into year spans", () => {
        expect(yearSpans(MONTHS.map((value) => ({ month: value, days: 30 })))).toEqual([
            { year: "2026", span: 3 },
            { year: "2027", span: 1 },
        ]);
    });

    it("draws the sparkline from the opening stock and puts shortages below the zero line", () => {
        const line = sparkline(sku(), 2)!;

        // 値は 90, 60, 30, 0, −30。最大90・最小−30 を高さ 6〜66 に割り当てる
        expect(line.zeroY).toBe(51);
        expect(line.line.startsWith("M 4.0 6.0 L")).toBe(true);
        expect(line.line.endsWith("236.0 66.0")).toBe(true);
        expect(line.checkX).toBe(178);
        expect(line.inboundMarks).toHaveLength(1);
        expect(sparkline(sku({ months: null, opening_stock: null }), 2)).toBeNull();
    });

    it.each([
        [180, "plenty"],
        [179, "enough"],
        [90, "enough"],
        [89, "some"],
        [60, "some"],
        [59, "limited"],
        [30, "limited"],
        [29, "low"],
        [15, "low"],
        [14, "critical"],
        [1, "critical"],
        [0, "empty"],
    ])("classifies ending stock %s by its months of cover", (ending, expected) => {
        const item: ItemTrend = { item_no: "fl-01", brand: "B", category: "C", unassignable_inbound: 0, skus: [sku({ months: [month(0, ending)] })] };
        const [group] = buildItemRows([item], [{ month: MONTHS[0]!, days: 30 }], 0);
        expect(group!.rows[0]!.cells[0]!.cover).toBe(expected);
    });

    it("keeps shortages distinct and treats stock without positive demand as plenty", () => {
        const item: ItemTrend = {
            item_no: "fl-01",
            brand: "B",
            category: "C",
            unassignable_inbound: 0,
            skus: [sku({ months: [month(0, 180, 1)] }), sku({ average_daily: 0, months: [month(0, 1)] })],
        };
        const [group] = buildItemRows([item], [{ month: MONTHS[0]!, days: 30 }], 0);
        expect(group!.rows.map((row) => row.cells[0]!.cover)).toEqual(["shortage", "plenty"]);
    });

    it("builds rows with cover levels, status labels and item totals", () => {
        const item: ItemTrend = { item_no: "fl-01", brand: "B", category: "C", unassignable_inbound: 0, skus: [sku()] };
        const [rows] = buildItemRows(
            [item],
            MONTHS.map((value) => ({ month: value, days: 30 })),
            2,
        );

        expect(rows!.rows[0]!.cells.map((cell) => cell.cover)).toEqual(["some", "limited", "empty", "shortage"]);
        expect(rows!.rows[0]!.status).toEqual({ label: "1月から欠品", isShortage: true });
        expect(rows!.rows[0]!.coverText).toBe("在庫90・3.0か月分");
        expect(rows!.monthTexts).toEqual(["60", "30", "0", "−30"]);
        expect(rows!.monthInbounds[1]!.text).toBe("+10");
        expect(rows!.shortageCount).toBe(1);
    });
});
