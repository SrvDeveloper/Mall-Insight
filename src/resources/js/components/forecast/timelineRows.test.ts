import { describe, expect, it } from "vitest";
import { columnEvents, formatFigure, formatVariance, timelineFigures, timelineItems, timelineLabels } from "@/components/forecast/timelineRows";
import { channel, dayColumns, monthColumns, sku, timeline } from "@/views/forecastTimeline.fixture";

describe("timelineRows", () => {
    it("sums the channels of the scope and measures the variance of the past months", () => {
        const items = timelineItems(timeline(), "total", "quantity");
        const fl01 = items.find((item) => item.itemNo === "fl-01")!.figures;

        expect(fl01.actual[0]).toBe(150);
        expect(fl01.replayed[0]).toBe(155);
        expect(fl01.variance[0]).toBeCloseTo(5 / 150);
        expect(fl01.wape).toBeCloseTo(5 / 150);
        expect(fl01.bias).toBeCloseTo(5 / 150);
        // 今月は前日までの実績、予測は1か月分
        expect(fl01.actual[12]).toBe(75);
        expect(fl01.forecast[12]).toBe(180);
        expect(fl01.forecastTotal).toBe(180 * 12);
        expect(fl01.pastActualTotal).toBe(150 * 12);
        expect(fl01.baseAverage).toBeCloseTo(6);

        // Amazon だけ
        const amazon = timelineItems(timeline(), "amazon", "quantity").find((item) => item.itemNo === "fl-01")!.figures;
        expect(amazon.variance[0]).toBeCloseTo(-0.1);
        expect(amazon.channels.boss).toBeUndefined();

        // 予測できないSKUの数
        expect(items.find((item) => item.itemNo === "si-13")!.unpredictableCount).toBe(1);
    });

    it("leaves stockout days out of the daily variance and uses the actual amount and unit prices for amounts", () => {
        const columns = dayColumns();
        const stocked = channel(columns, { actual: 10, replayed: 20, forecast: 20 }, { stockout: [true, false, false, null, null, null, null] });
        const figures = timelineFigures([sku("a", { boss: stocked }, { unit_prices: { total: 500, boss: 500, amazon: null } })], columns, "total", "quantity", "day");

        expect(figures.stockout.slice(0, 3)).toEqual([true, false, false]);
        expect(figures.variance.slice(0, 3)).toEqual([null, 1, 1]);
        expect(figures.wape).toBe(1);
        // 欠品した日も、予測し直した数と実績は出す
        expect(figures.replayed[0]).toBe(20);

        const amounts = timelineFigures([sku("a", { boss: stocked }, { unit_prices: { total: 500, boss: 500, amazon: null } })], columns, "total", "amount", "day");
        expect(amounts.actual[1]).toBe(10000);
        expect(amounts.replayed[1]).toBe(10000);
        expect(amounts.forecast[4]).toBe(10000);
        expect(amounts.variance[1]).toBe(0);
    });

    it("labels months and days and finds the events of each column", () => {
        expect(timelineLabels(monthColumns(), "month").slice(0, 4)).toEqual([
            { label: "10月", sub: "2025", weekend: false, title: "2025年10月" },
            { label: "11月", sub: "", weekend: false, title: "2025年11月" },
            { label: "12月", sub: "", weekend: false, title: "2025年12月" },
            { label: "1月", sub: "2026", weekend: false, title: "2026年1月" },
        ]);
        expect(timelineLabels(dayColumns(), "day")[0]).toEqual({ label: "10/10", sub: "土", weekend: true, title: "10月10日（土）" });

        const events = columnEvents(timeline("day"), "total");
        expect(events.map((list) => list.length)).toEqual([0, 0, 0, 0, 1, 1, 0]);
        expect(columnEvents(timeline("day"), "amazon").every((list) => list.length === 0)).toBe(true);
    });

    it("formats figures and variances", () => {
        expect(formatFigure(0.44, "quantity", "day")).toBe("0.4");
        expect(formatFigure(12.4, "quantity", "day")).toBe("12");
        expect(formatFigure(1234.5, "quantity", "month")).toBe("1,235");
        expect(formatFigure(123456, "amount", "month")).toBe("123");
        expect(formatFigure(null, "quantity", "month")).toBe("—");
        expect(formatVariance(0.123)).toBe("+12%");
        expect(formatVariance(-0.08)).toBe("−8%");
        expect(formatVariance(null)).toBe("");
    });
});
