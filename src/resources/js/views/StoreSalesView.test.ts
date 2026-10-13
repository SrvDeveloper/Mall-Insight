import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import StoreSalesView from "@/views/StoreSalesView.vue";
import { fetchStoreRanking, fetchStoreSales } from "@/api/actuals";
import type { StoreRankingResponse } from "@/types/api";
import { storeSalesResponse } from "@/views/storeSales.fixture";

vi.mock("@/api/actuals", () => ({ fetchStoreSales: vi.fn(), fetchStoreRanking: vi.fn() }));

function rankingResponse(order: "amount" | "quantity" = "amount"): StoreRankingResponse {
    return {
        data: {
            items: [
                { item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", sku_count: 2, quantity: 3, amount: 15000 },
                { item_no: "si-13", brand: "FEELLIFE", category: "調光老眼鏡", sku_count: 1, quantity: 1, amount: 4000 },
            ],
            skus: [
                { sku_id: 1, sku_code: "fl-01-1-10", item_no: "fl-01", quantity: 2, amount: 10000 },
                { sku_id: 9, sku_code: "unknown-1", item_no: null, quantity: 1, amount: 6000 },
            ],
            unregistered: { sku_count: 1, quantity: 1, amount: 6000 },
        },
        meta: {
            unit: "month",
            period: "2026-10",
            from: "2026-10-01",
            to: "2026-10-07",
            mall: "rakuten",
            mall_label: "楽天市場",
            products: "all",
            order,
            total: { quantity: 5, amount: 25000 },
        },
    };
}

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
        vi.mocked(fetchStoreRanking)
            .mockReset()
            .mockImplementation(async (_unit, _period, _mall, _products, order) => rankingResponse(order));
    });

    it("shows the sales of each store in thousand yen for all products by default", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("店舗売上");
        expect(fetchStoreSales).toHaveBeenCalledWith("month", "all", null);
        expect(wrapper.find('[data-testid="description"]').text()).toContain("CrossWalkerに未登録のSKUを含む");
        expect(wrapper.findAll("thead th").map((th) => th.text())).toEqual(["店舗", "期間計（千円）", "構成比", "26/8", "26/9", "26/10 今月"]);
        const rows = wrapper.findAll('[data-testid="store-row"]');
        expect(rows.map((row) => row.find("th").text())).toEqual(["楽天市場", "Amazon", "Yahoo!ショッピング", "au PAY マーケット"]);
        expect(rows[0]!.findAll('[data-testid="store-cell"]').map((cell) => cell.text())).toEqual(["30", "45", "25"]);
        expect(rows[1]!.findAll('[data-testid="store-cell"]')[0]!.text()).toBe("—");
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
        // 背景は透かす（K-085）
        expect(tooltip.classes()).toContain("bg-stone-900/85");
        expect(tooltip.find('[data-testid="tooltip-pick-hint"]').text()).toContain("この店舗の売上上位の品番・SKUを表の下に出します");
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

    it("shows the top items and SKUs of the store and month under the table when a cell is clicked", async () => {
        const { wrapper } = await mountView();

        // 販売実績の無い欄は押しても開かない
        await wrapper.findAll('[data-testid="store-row"]')[1]!.findAll("td[tabindex]")[0]!.trigger("click");
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(false);

        const cell = wrapper.findAll('[data-testid="store-row"]')[0]!.findAll("td[tabindex]")[2]!;
        await cell.trigger("click");
        await flushPromises();
        // 表の下に出し、押した欄を枠で示す
        expect(wrapper.find('[data-testid="store-sales-table"] ~ [data-testid="ranking-panel"]').exists()).toBe(true);
        expect(cell.attributes("data-selected")).toBe("true");
        expect(fetchStoreRanking).toHaveBeenCalledWith("month", "2026-10", "rakuten", "all", "amount");
        const dialog = wrapper.find('[data-testid="ranking-panel"]');
        expect(dialog.find("h2").text()).toBe("楽天市場・2026年10月（今日まで）の売上上位");
        expect(dialog.find('[data-testid="ranking-summary"]').text()).toBe("2026/10/01〜2026/10/07・全商品・合計 25,000円（5個）");
        const items = dialog.findAll('[data-testid="ranking-item"]');
        expect(items[0]!.text()).toContain("fl-01");
        expect(items[0]!.text()).toContain("15,000円");
        expect(items[0]!.text()).toContain("60.0%・3個");
        const skus = dialog.findAll('[data-testid="ranking-sku"]');
        expect(skus[1]!.text()).toContain("unknown-1未登録");
        expect(dialog.find('[data-testid="ranking-unregistered"]').text()).toContain("CrossWalkerに未登録のSKU（1 SKU）：6,000円・24.0%");

        await dialog.find('[data-testid="ranking-order-quantity"]').trigger("click");
        await flushPromises();
        expect(fetchStoreRanking).toHaveBeenLastCalledWith("month", "2026-10", "rakuten", "all", "quantity");
        expect(wrapper.find('[data-testid="ranking-item"]').text()).toContain("3個");

        await wrapper.find('[aria-label="売上上位を閉じる"]').trigger("click");
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(false);
        expect(cell.attributes("data-selected")).toBeUndefined();

        // 合計の行は全店舗
        await wrapper.find('[data-testid="store-total-row"]').findAll("td[tabindex]")[1]!.trigger("keydown", { key: "Enter" });
        await flushPromises();
        expect(fetchStoreRanking).toHaveBeenLastCalledWith("month", "2026-09", null, "all", "amount");
        expect(wrapper.find('[data-testid="ranking-panel"] h2').text()).toBe("全店舗・2026年9月の売上上位");

        // 同じ欄をもう一度押すと閉じる。見せ方を変えても閉じる
        await wrapper.find('[data-testid="store-total-row"]').findAll("td[tabindex]")[1]!.trigger("click");
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(false);
        await wrapper.find('[data-testid="store-total-row"]').findAll("td[tabindex]")[0]!.trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(true);
        await wrapper.find('[data-testid="view-graph"]').trigger("click");
        await flushPromises();
        await wrapper.find('[data-testid="view-table"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(false);
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
        expect(wrapper.findAll('[data-testid="store-tile"]').map((tile) => tile.text())).toEqual(["楽天市場2050.0%", "Amazon615.0%", "Yahoo!ショッピング1230.0%", "au PAY マーケット25.0%"]);
        expect(wrapper.findAll('[data-testid="legend-rakuten"]')).toHaveLength(1);
    });

    it("asks to confirm the target items before showing only them", async () => {
        const { wrapper } = await mountView("/store-sales?products=target");

        expect(wrapper.find('[data-testid="needs-selection"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="store-sales-table"]').exists()).toBe(false);
    });
});
