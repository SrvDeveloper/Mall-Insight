import { describe, expect, it } from "vitest";
import { storeSeries } from "@/components/actuals/actualRows";
import { buildStoreRows } from "@/components/actuals/storeSalesRows";
import { storeSalesResponse } from "@/views/storeSales.fixture";

describe("storeSalesRows", () => {
    it("sums the stores into the total, leaving stores without sales data out with a note", () => {
        const { total, stores } = buildStoreRows(storeSalesResponse().data, "quantity");

        expect(stores.map((row) => row.label)).toEqual(["楽天市場", "Yahoo!ショッピング", "au PAY マーケット", "Amazon"]);
        expect(stores[3]!.cells.map((cell) => cell.text)).toEqual(["—", "4", "2"]);
        expect(stores[3]!.cells[0]!.title).toBe("Amazonの販売実績を取り込んでいません");
        expect(total.cells.map((cell) => [cell.text, cell.excludes ?? false])).toEqual([
            ["10", true],
            ["19", false],
            ["11", false],
        ]);
        expect(total.cells[0]!.title).toBe("Amazonの販売実績を取り込んでいないため、合計に含みません");
        expect(total.summaryText).toBe("40");
        expect(stores[0]!.summaryText).toBe("20");
        expect(stores[0]!.shareText).toBe("50.0%");
        expect(total.shareText).toBe("100%");
    });

    it("shows amounts in thousand yen and paints each store in its own color", () => {
        const { stores } = buildStoreRows(storeSalesResponse().data, "amount");

        expect(stores[0]!.cells.map((cell) => cell.text)).toEqual(["30", "45", "25"]);
        const series = storeSeries(storeSalesResponse().data, "amount");
        expect(series.map((item) => [item.key, item.color])).toEqual([
            ["rakuten", "#2a78d6"],
            ["yahoo", "#1baf7a"],
            ["au_pay", "#4a3aa7"],
            ["amazon", "#eb6834"],
        ]);
        expect(series[0]!.previous).toEqual([null, 30000, 45000]);
    });

    it("leaves every column empty when no sales data is imported", () => {
        const response = storeSalesResponse();
        response.data.forEach((store) => {
            store.quantities = [null, null, null];
        });

        const { total } = buildStoreRows(response.data, "quantity");
        expect(total.cells.map((cell) => [cell.text, cell.tone])).toEqual([
            ["—", "none"],
            ["—", "none"],
            ["—", "none"],
        ]);
        expect(total.summaryText).toBe("—");
        expect(total.shareText).toBe("—");
    });
});
