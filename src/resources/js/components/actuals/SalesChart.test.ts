import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import SalesChart from "@/components/actuals/SalesChart.vue";
import type { ChartSeries } from "@/components/actuals/actualRows";

function mountChart(boss: (number | null)[], amazon: (number | null)[]) {
    const series: ChartSeries[] = [
        { key: "boss", label: "BOSS", color: "#2a78d6", values: boss },
        { key: "amazon", label: "Amazon", color: "#eb6834", values: amazon },
    ];
    return mount(SalesChart, {
        props: {
            kind: "line",
            height: 280,
            series,
            columns: boss.map((_, index) => ({ key: String(index), label: String(index), sub: "", muted: false })),
            formatValue: String,
            formatAxis: String,
            axisUnit: "個",
        },
    });
}

describe("SalesChart tooltip placement", () => {
    beforeEach(() => {
        vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function (this: Element) {
            if (this.tagName.toLowerCase() === "svg") {
                return new DOMRect(0, 28, 760, 280);
            }
            if (this.getAttribute("data-testid") === "chart-tooltip") {
                return new DOMRect(0, 0, 288, 220);
            }
            return new DOMRect(0, 0, 760, 308);
        });
    });

    afterEach(() => vi.restoreAllMocks());

    it("keeps the tooltip at the same height for points with different heights", async () => {
        const wrapper = mountChart([10, 100], [0, 10]);
        const bands = wrapper.findAll('[data-testid="chart-band"]');
        await bands[0]!.trigger("mouseenter");

        const tooltip = wrapper.find('[data-testid="chart-tooltip"]');
        expect((tooltip.element as HTMLElement).style.top).toBe("36px");
        expect(tooltip.classes()).toContain("bg-stone-900/85");

        await bands[1]!.trigger("mouseenter");
        expect((tooltip.element as HTMLElement).style.top).toBe("36px");
        wrapper.unmount();
    });

    it("switches sides near the right edge while keeping its height for keyboard focus", async () => {
        const wrapper = mountChart([20, 100], [80, 30]);
        const bands = wrapper.findAll('[data-testid="chart-band"]');
        await bands[0]!.trigger("mouseenter");

        const upperPoint = wrapper.find('[data-series="amazon"]').find('[data-testid="chart-point"]');
        const tooltip = wrapper.find('[data-testid="chart-tooltip"]');
        const style = (tooltip.element as HTMLElement).style;
        expect(style.top).toBe("36px");
        expect(parseFloat(style.left)).toBeGreaterThan(Number(upperPoint.attributes("cx")));
        expect(parseFloat(style.left) + 288).toBeLessThanOrEqual(760);

        await bands[1]!.trigger("focus");
        const rightPoint = wrapper.find('[data-series="boss"]').findAll('[data-testid="chart-point"]')[1]!;
        expect(parseFloat(style.left) + 288).toBeLessThan(Number(rightPoint.attributes("cx")));
        expect(style.top).toBe("36px");
        wrapper.unmount();
    });

    it("keeps missing sales data at the same height as months with sales", async () => {
        const wrapper = mountChart([null, 100], [null, 30]);
        await wrapper.findAll('[data-testid="chart-band"]')[0]!.trigger("mouseenter");

        const tooltip = wrapper.find('[data-testid="chart-tooltip"]');
        expect(tooltip.text()).toContain("販売実績を取り込んでいません");
        expect((tooltip.element as HTMLElement).style.top).toBe("36px");
        await wrapper.findAll('[data-testid="chart-band"]')[1]!.trigger("mouseenter");
        expect((tooltip.element as HTMLElement).style.top).toBe("36px");
        wrapper.unmount();
    });
});
