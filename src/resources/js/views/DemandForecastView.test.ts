import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import DemandForecastView from "@/views/DemandForecastView.vue";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import { ApiError } from "@/api/client";
import { timeline } from "@/views/forecastTimeline.fixture";

vi.mock("@/api/demandForecasts", () => ({ fetchForecastTimeline: vi.fn() }));

async function mountView(path = "/forecasts") {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/forecasts", name: "forecasts", component: DemandForecastView },
            { path: "/forecasts/:itemNo", name: "sku-forecasts", component: { template: "<div />" } },
        ],
    });
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

        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "month", offset: 0, period: null, itemNo: null });
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
        expect(wrapper.find('[data-testid="stat-bias"]').text()).toBe("+3.3%");
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

    it("fills the months without stock records with gray hatching in the stockout lane", async () => {
        const response = timeline();
        // fl-01 は、過去12か月のうち最初の5か月に在庫の記録が無い
        for (const data of Object.values(response.data[1]!.skus[0]!.channels)) {
            data.stockout = data.stockout.map((value, index) => (index < 5 ? null : value));
            data.stockout_known = data.stockout_known.map((value, index) => (index < 5 ? null : value));
        }
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        const { wrapper } = await mountView();

        const gaps = wrapper.findAll('[data-testid="stockout-gap"]');
        expect(gaps).toHaveLength(1);
        expect(gaps[0]!.classes()).toContain("hatch-unknown");
        expect(gaps[0]!.text()).toBe("在庫の記録なし");
        expect(gaps[0]!.attributes("style")).toContain("width: calc(0.2083 * (100% - 56px))");
        wrapper.unmount();
    });

    it("marks the items with a manual adjustment on the channels shown", async () => {
        const response = timeline();
        response.meta.adjustments = [
            {
                id: 1,
                item_no: "fl-01",
                channel: "amazon",
                channel_label: "Amazon",
                starts_on: "2026-11",
                ends_on: null,
                rate_percent: 120,
                reason: "広告を強める",
                status: "scheduled",
                created_at: null,
                cancelled_at: null,
            },
        ];
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        const { wrapper, router } = await mountView();

        expect(wrapper.findAll('[data-testid="rank-adjusted"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="rank-fl-01"] [data-testid="rank-adjusted"]').attributes("title")).toBe("予測の補正：Amazon 2026/11〜 120%");
        // BOSS だけを見ているときは、Amazon の補正の札を出さない
        await router.push({ query: { scope: "boss" } });
        await flushPromises();
        expect(wrapper.find('[data-testid="rank-adjusted"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("switches the mall, the measure, the forecast to compare and the filter in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="scope-amazon"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.scope).toBe("amazon");
        expect(wrapper.find('[data-testid="stat-bias"]').text()).toBe("−10.0%");

        await wrapper.find('[data-testid="measure-amount"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="stat-forecast"]').text()).toBe("720千円");

        await wrapper.find('[data-testid="offset-3"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.offset).toBe("3");
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "month", offset: 3, period: null, itemNo: null });

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
        // 10/11 は BOSS も Amazon も欠品していて、比べられない
        daily.data[1]!.skus[0]!.channels.boss!.stockout = [false, true, false, null, null, null, null];
        daily.data[1]!.skus[0]!.channels.amazon!.stockout = [false, true, false, null, null, null, null];
        vi.mocked(fetchForecastTimeline).mockImplementation(async (query) => (query.unit === "day" ? daily : timeline()));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="unit-day"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.unit).toBe("day");
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "day", offset: 0, period: null, itemNo: null });
        expect(wrapper.find('[data-testid="period-picker"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-large"]').text()).toContain("50%以上");
        expect(wrapper.findAll('[data-testid="event-band"]')).toHaveLength(1);
        // 欠品した日は地を黄色に、土日は灰色にする
        expect(wrapper.findAll('[data-testid="chart-band"]')[1]!.attributes("style")).toContain("background: rgb(254, 243, 199)");
        expect(wrapper.findAll('[data-testid="chart-band"]')[0]!.attributes("style")).toContain("background: rgb(250, 250, 249)");
        expect(wrapper.findAll('[data-testid="figure-variance"]')[1]!.text()).toBe("欠品");

        // 棒の下に欠品率の折れ線を出し、ツールチップに欠品したSKUの数を出す
        expect(wrapper.find('[data-testid="stockout-lane"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="stockout-line"]')).toHaveLength(1);
        // SVG は left・right だけでは横に伸びないため、幅を指定してグラフの幅に合わせる
        expect(wrapper.find('[data-testid="stockout-lane"] svg').attributes("style")).toContain("width: calc(100% - 56px)");
        await wrapper.findAll('[data-testid="chart-band"]')[1]!.trigger("mouseenter");
        const rows = wrapper.findAll('[data-testid="chart-tooltip"] p').map((row) => row.text());
        expect(rows).toEqual(expect.arrayContaining(["差異欠品の日は数えない", "欠品率100%", "BOSS の欠品したSKU1 / 1 SKU", "Amazon の欠品したSKU1 / 1 SKU"]));
        await wrapper.findAll('[data-testid="chart-band"]')[1]!.trigger("blur");

        await wrapper.find('[data-testid="period-button"]').trigger("click");
        await wrapper.find('[data-testid="period-d14"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toMatchObject({ unit: "day", from: "2026-09-29", to: "2026-10-27" });
        expect(fetchForecastTimeline).toHaveBeenLastCalledWith({ unit: "day", offset: 0, period: { from: "2026-09-29", to: "2026-10-27" }, itemNo: null });
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
        await flushPromises();
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
        await wrapper.find('[data-testid="period-button"]').trigger("click");
        // 日付の欄は値を変えると change が起き、選んだ期間で読み込み直す
        await wrapper.find('[data-testid="period-to"]').setValue("2027-03-01");
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
