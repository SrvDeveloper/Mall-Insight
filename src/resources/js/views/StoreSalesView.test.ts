import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import StoreSalesView from "@/views/StoreSalesView.vue";
import { fetchStoreRanking, fetchStoreSales } from "@/api/actuals";
import type { StoreRankingResponse } from "@/types/api";
import { storeSalesResponse } from "@/views/storeSales.fixture";

vi.mock("@/api/actuals", () => ({ fetchStoreSales: vi.fn(), fetchStoreRanking: vi.fn() }));

/**
 * 売上上位（K-091・K-095・K-096）。fl-01 は前月3位から1位で、BOSS で 1 SKU が 4日欠品（10月7日までのうち5日を確認）。si-13 は前月に売れていない。
 * mall が null（全店舗）なら BOSS と Amazon の欠品日数を返す。
 */
function rankingResponse(order: "amount" | "quantity" = "amount", mall: "rakuten" | null = "rakuten"): StoreRankingResponse {
    const both = mall === null;
    const stockout = (days: number | null, knownDays = 7) => ({ days, known_days: days === null ? 0 : knownDays, period_days: 7 });
    return {
        data: {
            items: [
                {
                    item_no: "fl-01",
                    brand: "FEELLIFE",
                    category: "老眼鏡",
                    sku_count: 2,
                    quantity: 3,
                    amount: 15000,
                    rank: 1,
                    previous_rank: 3,
                    stockouts: { boss: { ...stockout(4, 5), skus: 1 }, ...(both ? { amazon: { ...stockout(0), skus: 0 } } : {}) },
                },
                {
                    item_no: "si-13",
                    brand: "FEELLIFE",
                    category: "調光老眼鏡",
                    sku_count: 1,
                    quantity: 1,
                    amount: 4000,
                    rank: 2,
                    previous_rank: null,
                    stockouts: { boss: { ...stockout(0), skus: 0 } },
                },
            ],
            unregistered: { sku_count: 1, quantity: 1, amount: 6000 },
        },
        meta: {
            unit: "month",
            period: "2026-10",
            from: "2026-10-01",
            to: "2026-10-07",
            mall,
            mall_label: mall === null ? "全店舗" : "楽天市場",
            products: "all",
            order,
            total: { quantity: 5, amount: 25000 },
            previous: { from: "2026-09-01", to: "2026-09-30", available: true },
            stockout_channels: both
                ? [
                      { channel: "boss", label: "BOSS" },
                      { channel: "amazon", label: "Amazon" },
                  ]
                : [{ channel: "boss", label: "BOSS" }],
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
            .mockImplementation(async (_unit, _period, mall, _products, order) => rankingResponse(order, mall === null ? null : "rakuten"));
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

    it("shows the breakdown of a month only when the info button of the total row is pressed", async () => {
        const { wrapper } = await mountView();

        // 店舗の行にマウスを乗せても出さない
        const rows = wrapper.findAll('[data-testid="store-row"]');
        await rows[0]!.findAll("td[tabindex]")[2]!.trigger("mouseenter");
        expect(wrapper.find('[data-testid="table-tooltip"]').exists()).toBe(false);
        // (i) は合計の行の、販売実績のある欄だけ
        expect(wrapper.findAll('[data-testid="store-row"] [data-testid="info-button"]')).toHaveLength(0);
        const buttons = wrapper.find('[data-testid="store-total-row"]').findAll('[data-testid="info-button"]');
        expect(buttons).toHaveLength(3);

        await buttons[2]!.trigger("click");
        const tooltip = wrapper.find('[data-testid="table-tooltip"]');
        expect(tooltip.exists()).toBe(true);
        expect(buttons[2]!.attributes("aria-expanded")).toBe("true");
        // 背景は透かす（K-085）
        expect(tooltip.classes()).toContain("bg-stone-900/85");
        expect(tooltip.text()).toContain("26/10");
        // 金額は千円単位ではなく円で出す
        expect(tooltip.find('[data-testid="tooltip-total"]').text()).toBe("53,000円");
        expect(tooltip.find('[data-testid="tooltip-value-rakuten"]').text()).toBe("25,000円");
        expect(tooltip.find('[data-testid="tooltip-share-rakuten"]').text()).toBe("47.2%");
        // 前月（9月の同じ日まで）45,000円に対して
        expect(tooltip.find('[data-testid="tooltip-previous-rakuten"]').text()).toBe("56%");
        expect(tooltip.find('[data-testid="tooltip-last-year-rakuten"]').text()).toBe("—");
        // (i) を押しても売上上位は出さない
        expect(fetchStoreRanking).not.toHaveBeenCalled();

        // もう一度押すと閉じる
        await buttons[2]!.trigger("click");
        expect(wrapper.find('[data-testid="table-tooltip"]').exists()).toBe(false);

        // 販売実績を取り込んでいない店舗を除いた合計は、そのことを示す。外側を押すか Esc キーで閉じる
        await buttons[0]!.trigger("click");
        expect(wrapper.find('[data-testid="tooltip-missing"]').text()).toBe("Amazonは販売実績を取り込んでいないため、合計に含めていません");
        document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
        await flushPromises();
        expect(wrapper.find('[data-testid="table-tooltip"]').exists()).toBe(false);
        await buttons[1]!.trigger("click");
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[data-testid="table-tooltip"]').exists()).toBe(false);
    });

    it("shows the top items of the store and month under the table when a cell is clicked", async () => {
        const { wrapper } = await mountView();

        // 販売実績の無い欄は押しても開かない
        await wrapper.findAll('[data-testid="store-row"]')[1]!.findAll("td[tabindex]")[0]!.trigger("click");
        expect(wrapper.find('[data-testid="ranking-panel"]').exists()).toBe(false);

        const cell = wrapper.findAll('[data-testid="store-row"]')[0]!.findAll("td[tabindex]")[2]!;
        await cell.trigger("click");
        await flushPromises();
        // 押した欄を枠で示す
        // 店舗売上の表のセクションとは別のセクションとして出す（K-099）
        const panel = wrapper.find('[data-testid="ranking-panel"]');
        expect(panel.exists()).toBe(true);
        expect(wrapper.find('section[aria-busy] [data-testid="ranking-panel"]').exists()).toBe(false);
        expect(panel.element.previousElementSibling?.querySelector('[data-testid="store-sales-table"]')).not.toBeNull();
        expect(cell.attributes("data-selected")).toBe("true");
        expect(fetchStoreRanking).toHaveBeenCalledWith("month", "2026-10", "rakuten", "all", "amount");
        const dialog = wrapper.find('[data-testid="ranking-panel"]');
        expect(dialog.find("h2").text()).toBe("楽天市場・2026年10月（今日まで）の売上上位");
        expect(dialog.find('[data-testid="ranking-summary"]').text()).toBe("2026/10/01〜2026/10/07・全商品・合計 25,000円（5個）");
        const items = dialog.findAll('[data-testid="ranking-item"]');
        expect(items[0]!.text()).toContain("fl-01");
        expect(items[0]!.text()).toContain("15,000円");
        expect(items[0]!.text()).toContain("60.0%・3個");
        // 前月の順位からの変動と、欠品日数（品番は欠品したSKUの日数の合計）。札は欠品アイコンと日数だけにし、詳しくはマウスを乗せて見る
        expect(items.map((item) => item.find('[data-testid="rank-change"]').text())).toEqual(["▲2", "新"]);
        expect(items[0]!.find('[data-testid="rank-change"]').attributes("title")).toBe("前月は3位");
        const badge = items[0]!.find('[data-testid="stockout-badge"]');
        expect(badge.text()).toBe("4日");
        expect(badge.find('svg[aria-label="欠品"]').exists()).toBe(true);
        expect(badge.classes()).toContain("bg-red-50");
        expect(badge.attributes("title")).toBe("欠品 4日。欠品したSKU 1件の欠品日数の合計。在庫の記録で確認できたのは7日のうち5日");
        expect(items[1]!.find('[data-testid="stockout-badge"]').text()).toBe("0日");
        expect(dialog.findAll('[data-testid="ranking-sku"]')).toHaveLength(0);
        // 円グラフ（K-097）。上位の品番と未登録のSKUの割合を出し、円グラフに出した品番は一覧にも同じ色の印を付ける
        expect(dialog.findAll('[data-testid="donut-segment"]')).toHaveLength(3);
        expect(dialog.find('[data-testid="donut-legend"]').text()).toBe("1fl-0160.0%2si-1316.0%未登録のSKU24.0%");
        expect(dialog.find('[data-testid="ranking-chart-column"] + [aria-labelledby="ranking-items"]').exists()).toBe(true);
        expect(dialog.find('[data-testid="donut-center"]').text()).toBe("合計金額25,000円");
        await dialog.findAll('[data-testid="donut-segment"]')[0]!.trigger("mouseenter");
        expect(dialog.find('[data-testid="donut-center"]').text()).toBe("fl-0160.0%15,000円");
        expect(items[0]!.find('[data-testid="ranking-item-swatch"]').attributes("style")).toContain("background-color");
        expect(dialog.find('[data-testid="ranking-notes"]').text()).toContain("順位の変動は前月（2026/09/01〜2026/09/30）の順位との比較です");
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
        // 全店舗は BOSS と Amazon の欠品日数を分けて出す
        expect(
            wrapper
                .find('[data-testid="ranking-item"]')
                .findAll('[data-testid="stockout-badge"]')
                .map((badge) => badge.text()),
        ).toEqual(["BOSS 4日", "Amazon 0日"]);

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
