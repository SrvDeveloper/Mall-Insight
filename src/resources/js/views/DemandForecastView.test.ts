import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import DemandForecastView from "@/views/DemandForecastView.vue";
import { fetchDemandForecasts } from "@/api/demandForecasts";
import type { DemandForecastResponse, SkuForecast } from "@/types/api";

vi.mock("@/api/demandForecasts", () => ({ fetchDemandForecasts: vi.fn() }));

const months = Array.from({ length: 12 }, (_, index) => {
    const date = new Date(2026, 9 + index, 1);
    return { month: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`, days: index === 0 ? 25 : 30 };
});

function predictable(sku_id: number, sku_code: string, baseAverage: number): SkuForecast {
    return {
        sku_id,
        sku_code,
        status: "active",
        status_label: "有効",
        base_average: baseAverage,
        unpredictable_reason: null,
        unpredictable_reason_label: null,
        windows: [
            {
                length: 30,
                offset: 0,
                label: "直近30日",
                from: "2026-09-07",
                to: "2026-10-06",
                sales_days: 30,
                stockout_days: 0,
                sales_quantity: 60,
                average: 2,
                base_weight: 0.5,
                weight: 0.5,
                is_excluded: false,
            },
            {
                length: 60,
                offset: 30,
                label: "31〜90日前",
                from: "2026-07-09",
                to: "2026-09-06",
                sales_days: 57,
                stockout_days: 3,
                sales_quantity: 90,
                average: 1.5,
                base_weight: 0.3,
                weight: 0.3,
                is_excluded: false,
            },
            {
                length: 90,
                offset: 90,
                label: "91〜180日前",
                from: "2026-04-10",
                to: "2026-07-08",
                sales_days: 90,
                stockout_days: 0,
                sales_quantity: 135,
                average: 1.5,
                base_weight: 0.2,
                weight: 0.2,
                is_excluded: false,
            },
        ],
        monthly: months.map((month) => ({ ...month, quantity: baseAverage * month.days })),
    };
}

const unpredictable: SkuForecast = {
    ...predictable(3, "fl-01-1-20", 0),
    base_average: null,
    monthly: null,
    unpredictable_reason: "no_sales",
    unpredictable_reason_label: "直近180日に販売実績がありません",
};

function response(overrides: Partial<DemandForecastResponse["meta"]> = {}, data: DemandForecastResponse["data"] | null = null): DemandForecastResponse {
    return {
        data: data ?? [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", skus: [predictable(1, "fl-01-1-10", 1.75), predictable(2, "fl-01-1-15", 0.5), unpredictable] }],
        meta: {
            calculated_at: "2026-10-07T10:00:00+09:00",
            sales_data_from: "2025-10-07",
            sales_data_to: "2026-10-06",
            forecast_from: "2026-10-07",
            selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 },
            window_weights: [
                { length: 30, weight: 0.5 },
                { length: 60, weight: 0.3 },
                { length: 90, weight: 0.2 },
            ],
            min_sales_days: 14,
            ...overrides,
        },
    };
}

async function mountView() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: DemandForecastView }] });
    const wrapper = mount(DemandForecastView, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper;
}

describe("DemandForecastView", () => {
    beforeEach(() => {
        vi.mocked(fetchDemandForecasts).mockReset();
    });

    it("shows the basis of the calculation", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());

        const wrapper = await mountView();

        const basis = wrapper.find('[data-testid="forecast-basis"]').text();
        expect(basis).toContain("2026/10/07 10:00");
        expect(basis).toContain("2025/10/07 〜 2026/10/06");
        expect(basis).toContain("1品番（2026/10/07 09:00 確定）");
        expect(wrapper.find('[data-testid="stale-sales"]').exists()).toBe(false);
    });

    it("shows monthly forecasts per SKU with item totals that leave out unpredictable SKUs", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());

        const wrapper = await mountView();

        const headers = wrapper.findAll("thead th").map((th) => th.text());
        expect(headers[2]).toBe("26/10 25日分");
        expect(headers[4]).toBe("26/12");
        const skuRows = wrapper.findAll('[data-testid="sku-row"]');
        const cells = skuRows[0]!.findAll("td").map((td) => td.text());
        expect(cells[1]).toBe("1.75");
        expect(cells[2]).toBe("44");
        expect(cells[3]).toBe("53");
        const itemCells = wrapper
            .find('[data-testid="item-row"]')
            .findAll("td")
            .map((td) => td.text());
        expect(itemCells[0]).toBe("予測不能を除く");
        expect(itemCells[1]).toBe("56");
        expect(skuRows[2]!.text()).toContain("予測不能");
        expect(skuRows[2]!.find('[data-testid="unpredictable-reason"]').text()).toBe("直近180日に販売実績がありません");
        expect(wrapper.find('[data-testid="forecast-summary"]').text()).toContain("予測不能 1件");
    });

    it("shows the windows and weights in a tooltip when hovering over the base average", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());
        const wrapper = await mountView();
        vi.useFakeTimers();

        try {
            const average = wrapper.findAll('[data-testid="base-average"]')[0]!;
            await average.trigger("mouseenter");
            expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
            await vi.advanceTimersByTimeAsync(200);

            const tooltip = wrapper.find('[role="tooltip"]');
            expect(tooltip.text()).toContain("fl-01-1-10 の基準平均日販 1.75");
            expect(tooltip.text()).toContain("直近30日");
            expect(tooltip.text()).toContain("2026/09/07 〜 2026/10/06");
            expect(tooltip.findAll("tbody tr")[1]!.text()).toContain("31〜90日前");
            expect(tooltip.findAll("tbody tr")[1]!.text()).toContain("2026/07/09 〜 2026/09/06");
            expect(tooltip.findAll("tbody tr")[1]!.text()).toContain("1.50");
            expect(tooltip.text()).toContain("欠品日数");
            expect(tooltip.findAll("tbody tr")[1]!.findAll("td")[4]!.text()).toBe("3");
            expect(average.attributes("aria-describedby")).toBe("average-tooltip");

            await average.trigger("mouseleave");
            expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it("shows the reason in the tooltip for an unpredictable SKU when focused", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());
        const wrapper = await mountView();

        const average = wrapper.findAll('[data-testid="base-average"]')[2]!;
        expect(average.text()).toBe("予測不能");
        await average.trigger("focus");
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').text()).toContain("直近180日に販売実績がありません");
    });

    it("can show only unpredictable SKUs", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());
        const wrapper = await mountView();

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(wrapper.findAll('[data-testid="sku-row"]').map((row) => row.text())).toEqual([expect.stringContaining("fl-01-1-20")]);
    });

    it("warns when the latest sales data is a week old", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ sales_data_to: "2026-09-30" }));

        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="stale-sales"]').text()).toContain("最新日から7日たっています。");
    });

    it("asks to confirm target items first", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ selection: null }, []));

        const wrapper = await mountView();

        expect(wrapper.text()).toContain("対象品番がまだ確定されていません。");
        expect(wrapper.find("a").attributes("href")).toBe("/target-items");
    });
});
