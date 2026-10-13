import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import StoreSalesView from "@/views/StoreSalesView.vue";
import { fetchStoreSales } from "@/api/actuals";
import { storeSalesResponse } from "@/views/storeSales.fixture";

vi.mock("@/api/actuals", () => ({ fetchStoreSales: vi.fn() }));

async function mountView(path = "/store-sales") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: StoreSalesView }] });
    await router.push(path);
    const wrapper = mount(StoreSalesView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("StoreSalesView", () => {
    beforeEach(() => {
        vi.mocked(fetchStoreSales).mockReset().mockResolvedValue(storeSalesResponse());
    });

    it("shows the sales of each store in thousand yen for all products by default", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("店舗売上");
        expect(fetchStoreSales).toHaveBeenCalledWith("month", "all", null);
        expect(wrapper.find('[data-testid="description"]').text()).toContain("CrossWalkerに未登録のSKUを含む");
        expect(wrapper.findAll("thead th").map((th) => th.text())).toEqual(["店舗", "期間計（千円）", "構成比", "26/8", "26/9", "26/10 今月"]);
        const rows = wrapper.findAll('[data-testid="store-row"]');
        expect(rows.map((row) => row.find("th").text())).toEqual(["楽天市場", "Yahoo!ショッピング", "au PAY マーケット", "Amazon"]);
        expect(rows[0]!.findAll('[data-testid="store-cell"]').map((cell) => cell.text())).toEqual(["30", "45", "25"]);
        expect(rows[3]!.findAll('[data-testid="store-cell"]')[0]!.text()).toBe("—");
        const total = wrapper.find('[data-testid="store-total-row"]');
        expect(total.find('[data-testid="store-summary"]').text()).toBe("194");
        expect(total.findAll('[data-testid="store-cell-excludes"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="excludes-note"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("BOSS 2025/09/01〜2026/10/06・Amazon 2026/09/01〜2026/10/06");
    });

    it("switches to the target items, quantities and the graph, keeping them in the URL", async () => {
        const response = storeSalesResponse();
        response.meta.selection = { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 };
        vi.mocked(fetchStoreSales).mockResolvedValue(response);
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="products-target"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ products: "target" });
        expect(fetchStoreSales).toHaveBeenLastCalledWith("month", "target", null);

        await wrapper.find('[data-testid="measure-quantity"]').trigger("click");
        await flushPromises();
        await wrapper.find('[data-testid="view-graph"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ products: "target", measure: "quantity", view: "graph" });
        expect(wrapper.findAll('[data-testid="store-tile"]').map((tile) => tile.text())).toEqual(["楽天市場2050.0%", "Yahoo!ショッピング1230.0%", "au PAY マーケット25.0%", "Amazon615.0%"]);
        expect(wrapper.findAll('[data-testid="legend-rakuten"]')).toHaveLength(1);
    });

    it("asks to confirm the target items before showing only them", async () => {
        const { wrapper } = await mountView("/store-sales?products=target");

        expect(wrapper.find('[data-testid="needs-selection"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="store-sales-table"]').exists()).toBe(false);
    });
});
