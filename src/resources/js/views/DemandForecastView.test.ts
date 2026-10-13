import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import DemandForecastView from "@/views/DemandForecastView.vue";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import { ApiError } from "@/api/client";
import { timeline } from "@/views/forecastTimeline.fixture";

vi.mock("@/api/demandForecasts", () => ({ fetchForecastTimeline: vi.fn() }));

async function mountView(path = "/forecasts") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: DemandForecastView }] });
    await router.push(path);
    const wrapper = mount(DemandForecastView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

describe("DemandForecastView", () => {
    beforeEach(() => {
        vi.mocked(fetchForecastTimeline)
            .mockReset()
            .mockImplementation(async (query) => timeline(query.unit));
    });

    it("lists the items by forecast and shows the selected item with its variance from the actual sales", async () => {
        const { wrapper } = await mountView();

        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "month", offset: 0, period: null });
        expect(wrapper.find("h1").text()).toBe("需要予測");
        const ranks = wrapper.findAll('[data-testid^="rank-"][aria-pressed]');
        expect(ranks.map((rank) => rank.find(".font-mono").text())).toEqual(["fl-01", "si-13"]);
        expect(wrapper.find('[data-testid="rank-wape-fl-01"]').text()).toBe("差異 3%");
        // 差異が30%以上の品番は強調する
        expect(wrapper.find('[data-testid="rank-wape-si-13"]').classes()).toContain("text-amber-700");
        expect(wrapper.find('[data-testid="rank-unpredictable"]').text()).toBe("予測不能 1 SKU");

        expect(wrapper.find('[data-testid="chart-item-no"]').text()).toBe("fl-01");
        expect(wrapper.find('[data-testid="stat-forecast"]').text()).toBe("2,160個");
        expect(wrapper.find('[data-testid="stat-wape"]').text()).toBe("3.3%");
        expect(wrapper.find('[data-testid="stat-bias"]').text()).toBe("+3%");
        expect(wrapper.find('[data-testid="stat-base"]').text()).toContain("6.0");
        expect(wrapper.findAll('[data-testid="replay-tick"]')).toHaveLength(12);
        expect(wrapper.find('[data-testid="today-line"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="figure-actual"]')[0]!.text()).toBe("150");
        expect(wrapper.findAll('[data-testid="figure-forecast"]')[0]!.text()).toBe("155");
        expect(wrapper.findAll('[data-testid="figure-variance"]')[0]!.text()).toBe("+3%");
        expect(wrapper.findAll('[data-testid="figure-forecast"]')[12]!.text()).toBe("180");

        await wrapper.find('[data-testid="rank-si-13"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="chart-item-no"]').text()).toBe("si-13");
        expect(wrapper.findAll('[data-testid="figure-variance"]')[0]!.classes()).toContain("font-bold");
        wrapper.unmount();
    });

    it("switches the mall, the measure, the forecast to compare and the filter in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="scope-amazon"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.scope).toBe("amazon");
        expect(wrapper.find('[data-testid="stat-bias"]').text()).toBe("−10%");

        await wrapper.find('[data-testid="measure-amount"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="stat-forecast"]').text()).toBe("720千円");

        await wrapper.find('[data-testid="offset-3"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.offset).toBe("3");
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "month", offset: 3, period: null });

        await wrapper.find('[data-testid="scope-total"]').trigger("click");
        await flushPromises();
        await wrapper.find('[data-testid="filter-large"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="filter-large"]').text()).toContain("1");
        expect(wrapper.findAll('[data-testid^="rank-"][aria-pressed]').map((rank) => rank.find(".font-mono").text())).toEqual(["si-13"]);
        wrapper.unmount();
    });

    it("shows the days with the period picker and leaves stockout days out of the variance", async () => {
        const daily = timeline("day");
        daily.data[1]!.skus[0]!.channels.boss!.stockout = [false, true, false, null, null, null, null];
        vi.mocked(fetchForecastTimeline).mockImplementation(async (query) => (query.unit === "day" ? daily : timeline()));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="unit-day"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.unit).toBe("day");
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "day", offset: 0, period: null });
        expect(wrapper.find('[data-testid="period-picker"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-large"]').text()).toContain("50%以上");
        expect(wrapper.findAll('[data-testid="event-band"]')).toHaveLength(1);
        expect(wrapper.findAll('[data-testid="chart-shade"]').length).toBeGreaterThan(0);
        expect(wrapper.findAll('[data-testid="figure-variance"]')[1]!.text()).toBe("欠品");

        await wrapper.find('[data-testid="period-d14"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toMatchObject({ unit: "day", from: "2026-09-29", to: "2026-10-27" });
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "day", offset: 0, period: { from: "2026-09-29", to: "2026-10-27" } });
        wrapper.unmount();
    });

    it("shows the table with the actual sales above and the forecast made again with its variance below, and opens the SKU rows", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="view-table"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.view).toBe("table");
        const rows = wrapper.findAll('[data-testid="table-item-row"]');
        expect(rows.map((row) => row.find(".font-mono").text())).toEqual(["fl-01", "si-13"]);
        // 過去6か月・今月・これからの11か月
        expect(rows[0]!.findAll('[data-testid="table-cell"]')).toHaveLength(18);
        expect(rows[0]!.findAll('[data-testid="table-cell"]')[0]!.text()).toBe("150155（+3%）");
        expect(rows[0]!.findAll('[data-testid="table-cell"]')[6]!.text()).toContain("180");
        expect(rows[0]!.find('[data-testid="table-total"]').text()).toBe("2,160");
        expect(rows[1]!.findAll('[data-testid="table-cell"]')[0]!.classes()).toContain("bg-amber-50");
        expect(rows[1]!.find('[data-testid="table-wape"]').classes()).toContain("text-amber-700");

        await wrapper.find('[data-testid="past-12"]').trigger("click");
        expect(wrapper.findAll('[data-testid="table-item-row"]')[0]!.findAll('[data-testid="table-cell"]')).toHaveLength(24);

        expect(wrapper.findAll('[data-testid="table-sku-row"]')).toHaveLength(0);
        await wrapper.find('[data-testid="toggle-si-13"]').trigger("click");
        const skuRows = wrapper.findAll('[data-testid="table-sku-row"]');
        expect(skuRows).toHaveLength(2);
        expect(skuRows[1]!.text()).toContain("予測不能：販売日数が足りない");
        wrapper.unmount();
    });

    it("shows why a period was not accepted and asks to confirm the target items first", async () => {
        vi.mocked(fetchForecastTimeline).mockImplementation(async (query) => {
            if (query.period) {
                throw new ApiError("入力内容を確認してください。", 422, { to: ["日ごとの予測は、今日から90日先までです。"] });
            }
            return timeline(query.unit);
        });
        const { wrapper } = await mountView("/forecasts?unit=day");
        await wrapper.find('[data-testid="period-to"]').setValue("2027-03-01");
        await wrapper.find('[data-testid="period-to"]').trigger("change");
        await flushPromises();
        expect(wrapper.find('[data-testid="period-error"]').text()).toBe("日ごとの予測は、今日から90日先までです。");
        wrapper.unmount();

        const empty = timeline();
        empty.meta.selection = null;
        vi.mocked(fetchForecastTimeline).mockResolvedValue(empty);
        const second = await mountView();
        expect(second.wrapper.find('[data-testid="no-selection"]').exists()).toBe(true);
        second.wrapper.unmount();
    });
});
