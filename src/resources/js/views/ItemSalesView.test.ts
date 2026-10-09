import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import ItemSalesView from "@/views/ItemSalesView.vue";
import { fetchSalesActuals } from "@/api/actuals";
import { salesResponse } from "@/views/salesActuals.fixture";

vi.mock("@/api/actuals", () => ({ fetchSalesActuals: vi.fn() }));

async function mountView(path = "/item-sales") {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/item-sales", name: "item-sales", component: ItemSalesView },
            { path: "/item-sales/:itemNo", name: "sku-sales", component: { template: "<div />" } },
        ],
    });
    await router.push(path);
    const wrapper = mount(ItemSalesView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("ItemSalesView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesActuals).mockReset().mockResolvedValue(salesResponse());
    });

    it("lists only the item totals without SKU rows", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("品番別売上");
        expect(fetchSalesActuals).toHaveBeenCalledWith("total", "month", null, null);
        expect(wrapper.findAll("thead th").map((th) => th.text())).toEqual(["品番", "期間計", "26/8 取込なし", "26/9", "26/10 今月"]);
        expect(wrapper.findAll('[data-testid="sku-row"]')).toHaveLength(0);
        const rows = wrapper.findAll('[data-testid="item-link-row"]');
        expect(rows).toHaveLength(2);
        // fl-01 は SKU の合計（4 と 5）
        expect(rows[0]!.findAll('[data-testid="actual-cell"]').map((cell) => cell.text())).toEqual(["—", "4", "5"]);
        expect(rows[0]!.find('[data-testid="item-summary"]').text()).toBe("9");
        expect(wrapper.text()).not.toContain("fl-01-1-10");
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("BOSS 2026/09/01〜2026/10/06・Amazon 未取込");
    });

    it("moves to the SKU sales of the item, keeping the selected mall, unit and measure", async () => {
        const { wrapper, router } = await mountView("/item-sales?scope=boss&unit=day&measure=amount");

        const link = wrapper.findAll('[data-testid="item-link"]')[1]!;
        expect(link.attributes("href")).toBe("/item-sales/si-13?scope=boss&unit=day&measure=amount");
        await link.trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.name).toBe("sku-sales");
        expect(router.currentRoute.value.params.itemNo).toBe("si-13");
    });

    it("switches to amounts and to days, keeping them in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="measure-amount"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ measure: "amount" });
        expect(wrapper.findAll("thead th")[1]!.text()).toBe("期間計（千円）");
        expect(
            wrapper
                .findAll('[data-testid="item-link-row"]')[0]!
                .findAll('[data-testid="actual-cell"]')
                .map((cell) => cell.text()),
        ).toEqual(["—", "22", "28"]);
        expect(wrapper.find('[data-testid="amount-note"]').text()).toContain("税込");

        await wrapper.find('[data-testid="unit-day"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ measure: "amount", unit: "day" });
        expect(fetchSalesActuals).toHaveBeenLastCalledWith("total", "day", null, null);
    });

    it("shows the items ranked by the period total and the stacked chart of the selected item by mall", async () => {
        const { wrapper, router } = await mountView("/item-sales?view=graph");

        // si-13 は 10、fl-01 は 9。多い順に並べ、はじめは一番多い品番のグラフを出す
        const ranks = wrapper.findAll('[data-testid^="rank-"]');
        expect(ranks.map((rank) => rank.attributes("data-testid"))).toEqual(["rank-si-13", "rank-fl-01"]);
        expect(ranks[0]!.text()).toContain("52.6%");
        expect(wrapper.find('[data-testid="chart-item-no"]').text()).toBe("si-13");
        expect(wrapper.find('[data-testid="chart-legend"]').text()).toContain("BOSS");

        await ranks[1]!.trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ view: "graph", item: "fl-01" });
        expect(wrapper.find('[data-testid="chart-item-no"]').text()).toBe("fl-01");
        // 9月は BOSS 3・Amazon 1、10月は BOSS 5 だけ
        expect(wrapper.findAll('[data-testid="chart-segment"]').map((segment) => segment.attributes("data-series"))).toEqual(["boss", "amazon", "boss"]);
        expect(wrapper.find('[data-testid="chart-sku-link"]').attributes("href")).toBe("/item-sales/fl-01?view=graph");
    });

    it("shows the total and the breakdown of the month in a tooltip", async () => {
        const { wrapper } = await mountView("/item-sales?view=graph&item=fl-01&measure=amount");

        const bands = wrapper.findAll('[data-testid="chart-band"]');
        await bands[1]!.trigger("mouseenter");
        const tooltip = wrapper.find('[data-testid="chart-tooltip"]');
        expect(tooltip.find('[data-testid="tooltip-total"]').text()).toBe("22,000円");
        expect(tooltip.text()).toContain("BOSS");
        expect(tooltip.text()).toContain("16,500円");
        expect(tooltip.text()).toContain("5,500円");

        await bands[0]!.trigger("mouseenter");
        expect(wrapper.find('[data-testid="chart-tooltip"]').text()).toContain("販売実績を取り込んでいません");
    });

    it("chooses the period from the presets and passes it on to the SKU sales", async () => {
        const { wrapper, router } = await mountView();

        // 初期値（今月と、その前の12か月）は「直近12か月」と同じ
        expect(wrapper.find('[data-testid="period-from"]').element).toHaveProperty("value", "2026-08");
        await wrapper.find('[data-testid="period-fy"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ from: "2026-04", to: "2026-10" });
        expect(fetchSalesActuals).toHaveBeenLastCalledWith("total", "month", null, { from: "2026-04", to: "2026-10" });
        expect(wrapper.find('[data-testid="item-link"]').attributes("href")).toBe("/item-sales/fl-01?from=2026-04&to=2026-10");

        // 月ごと・日ごとを切り替えると、期間は初期値に戻る
        await wrapper.find('[data-testid="unit-day"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ unit: "day" });
    });

    it("asks to confirm target items first", async () => {
        vi.mocked(fetchSalesActuals).mockResolvedValue({ ...salesResponse(), data: [], meta: { ...salesResponse().meta, selection: null } });
        const { wrapper } = await mountView();

        expect(wrapper.text()).toContain("対象品番がまだ確定されていません。");
    });
});
