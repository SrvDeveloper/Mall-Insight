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

    it("shows the details of a month in a tooltip on the table, highlighting the store", async () => {
        const { wrapper } = await mountView();

        const rows = wrapper.findAll('[data-testid="store-row"]');
        await rows[0]!.findAll("td[tabindex]")[2]!.trigger("mouseenter");
        const tooltip = wrapper.find('[data-testid="table-tooltip"]');
        expect(tooltip.exists()).toBe(true);
        expect(tooltip.text()).toContain("26/10");
        // 金額は千円単位ではなく円で出す
        expect(tooltip.find('[data-testid="tooltip-total"]').text()).toBe("53,000円");
        expect(tooltip.find('[data-testid="tooltip-value-rakuten"]').text()).toBe("25,000円");
        expect(tooltip.find('[data-testid="tooltip-share-rakuten"]').text()).toBe("47.2%");
        // 前月（9月の同じ日まで）45,000円に対して
        expect(tooltip.find('[data-testid="tooltip-previous-rakuten"]').text()).toBe("56%");
        expect(tooltip.find('[data-testid="tooltip-last-year-rakuten"]').text()).toBe("—");
        expect(tooltip.find('[data-highlighted="true"]').text()).toBe("楽天市場");
        await rows[0]!.findAll("td[tabindex]")[2]!.trigger("mouseleave");
        expect(wrapper.find('[data-testid="table-tooltip"]').exists()).toBe(false);

        // 販売実績を取り込んでいない店舗を除いた合計は、そのことを示す
        await wrapper.find('[data-testid="store-total-row"]').findAll("td[tabindex]")[0]!.trigger("focus");
        expect(wrapper.find('[data-testid="tooltip-missing"]').text()).toBe("Amazonは販売実績を取り込んでいないため、合計に含めていません");
        expect(wrapper.find('[data-testid="table-tooltip"] [data-highlighted="true"]').exists()).toBe(false);
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
