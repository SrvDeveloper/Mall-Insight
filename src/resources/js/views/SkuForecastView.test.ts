import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SkuForecastView from "@/views/SkuForecastView.vue";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import { timeline } from "@/views/forecastTimeline.fixture";
import type { ForecastTimelineResponse, SkuForecast } from "@/types/api";

vi.mock("@/api/demandForecasts", () => ({ fetchForecastTimeline: vi.fn() }));

function detail(): Omit<SkuForecast, "unit_prices"> {
    const monthly = ["2026-10", "2026-11", "2026-12"].map((month, index) => ({
        month,
        days: 30,
        quantity: index === 2 ? 140 : 120,
        event_quantity: index === 2 ? 20 : 0,
        seasonal_factor: null,
        events: index === 2 ? [{ period_id: 7, event_id: 3, name: "楽天スーパーセール", channel: "boss" as const, days: 4, multiplier: 2, is_candidate: true, quantity: 20 }] : [],
    }));
    return {
        sku_id: 1,
        sku_code: "fl-01-1",
        status: null,
        status_label: null,
        base_average: 6,
        monthly,
        is_partial: true,
        unpredictable_reason_label: "Amazon：販売日数が足りない",
        channels: [
            {
                channel: "boss",
                channel_label: "BOSS",
                base_average: 4,
                unpredictable_reason: null,
                unpredictable_reason_label: null,
                windows: [
                    {
                        length: 30,
                        offset: 0,
                        label: "直近30日",
                        from: "2026-09-12",
                        to: "2026-10-11",
                        sales_days: 27,
                        stockout_days: 3,
                        event_days: 0,
                        seasonal_factor: null,
                        sales_quantity: 108,
                        average: 4,
                        base_weight: 0.5,
                        weight: 0.5,
                        is_excluded: false,
                    },
                ],
                monthly,
            },
            { channel: "amazon", channel_label: "Amazon", base_average: null, unpredictable_reason: "insufficient_days", unpredictable_reason_label: "販売日数が足りない", windows: [], monthly: null },
        ],
    };
}

function itemTimeline(): ForecastTimelineResponse {
    const response = timeline();
    response.data = [response.data[1]!];
    response.data[0]!.skus[0]!.detail = detail();
    return response;
}

async function mountView(path = "/forecasts/fl-01") {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/forecasts", name: "forecasts", component: { template: "<div />" } },
            { path: "/forecasts/:itemNo", name: "sku-forecasts", component: SkuForecastView },
        ],
    });
    await router.push(path);
    const wrapper = mount(SkuForecastView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

describe("SkuForecastView", () => {
    beforeEach(() => {
        vi.mocked(fetchForecastTimeline).mockReset().mockResolvedValue(itemTimeline());
    });

    it("shows the item totals, the SKU chart and the basis of the forecast", async () => {
        const { wrapper } = await mountView("/forecasts/fl-01?scope=boss");

        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "month", offset: 0, period: null, itemNo: "fl-01" });
        expect(wrapper.find('[data-testid="item-no"]').text()).toBe("fl-01");
        expect(wrapper.find('[data-testid="sku-stat-forecast"]').text()).toBe("1,440個");
        expect(wrapper.find('[data-testid="sku-stat-share"]').text()).toBe("BOSS 100%");
        expect(wrapper.find('[data-testid="sku-stat-wape"]').text()).toBe("10.0%");
        expect(wrapper.find('[data-testid="sku-code"]').text()).toBe("fl-01-1");
        expect(wrapper.findAll('[data-testid="replay-tick"]')).toHaveLength(12);

        // 表示しているモール（BOSS）の根拠だけを出す
        expect(wrapper.find('[data-testid="basis-boss"] [data-testid="basis-average"]').text()).toBe("4.00 個/日");
        expect(wrapper.find('[data-testid="basis-amazon"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="breakdown-total"]').map((cell) => cell.text())).toEqual(["120", "120", "140"]);
        expect(wrapper.findAll('[data-testid="breakdown-event"]')[2]!.text()).toBe("+20（楽天スー）");
        wrapper.unmount();
    });

    it("shows why a channel cannot be forecast and goes back with the selections kept", async () => {
        const { wrapper, router } = await mountView("/forecasts/fl-01?unit=day&offset=3");
        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "day", offset: 3, period: null, itemNo: "fl-01" });

        const response = itemTimeline();
        response.data[0]!.skus[0]!.unpredictable_reason_label = "Amazon：販売日数が足りない";
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        await router.replace({ query: {} });
        await flushPromises();
        expect(wrapper.find('[data-testid="sku-reason"]').text()).toBe("予測できません：Amazon：販売日数が足りない");
        expect(wrapper.find('[data-testid="basis-amazon"] [data-testid="basis-reason"]').text()).toBe("予測できません：販売日数が足りない");

        await wrapper.find('[data-testid="back-to-forecasts"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.name).toBe("forecasts");
        expect(router.currentRoute.value.query).toEqual({ item: "fl-01" });
        wrapper.unmount();
    });
});
