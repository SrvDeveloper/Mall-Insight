import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import StockActualsView from "@/views/StockActualsView.vue";
import { fetchStockActuals } from "@/api/actuals";
import type { StockActualsResponse } from "@/types/api";

vi.mock("@/api/actuals", () => ({ fetchSalesActuals: vi.fn(), fetchStockActuals: vi.fn() }));

const meta = { calculated_at: "2026-10-07T10:00:00+09:00", scope_label: "全体", selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 } };

function stockResponse(): StockActualsResponse {
    return {
        data: [
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [
                    {
                        sku_id: 1,
                        sku_code: "fl-01-1-10",
                        color_no: "1",
                        size: "10",
                        status: "active",
                        status_label: "有効",
                        not_sold: false,
                        quantities: [null, 12, 0, null],
                        estimates: [null, null, null, { quantity: 0, base_quantity: 0, sold: 0 }],
                    },
                ],
            },
        ],
        meta: {
            ...meta,
            scope: "total",
            scope_warehouses: [{ warehouse: "free_stock", label: "フリー在庫" }],
            dates: [
                { date: "2026-10-04", has_data: false, estimate: "no_base", estimate_base_date: null },
                { date: "2026-10-05", has_data: true, estimate: null, estimate_base_date: null },
                { date: "2026-10-06", has_data: true, estimate: null, estimate_base_date: null },
                { date: "2026-10-07", has_data: false, estimate: "estimated", estimate_base_date: "2026-10-06" },
            ],
        },
    };
}

async function mountView(path = "/stock-actuals") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: StockActualsView }] });
    await router.push(path);
    const wrapper = mount(StockActualsView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("StockActualsView", () => {
    beforeEach(() => {
        vi.mocked(fetchStockActuals).mockReset().mockResolvedValue(stockResponse());
    });

    it("shows the daily stock with days without data as a dash and zero stock in red", async () => {
        const { wrapper, router } = await mountView();

        expect(wrapper.find("h1").text()).toBe("在庫推移");
        expect(fetchStockActuals).toHaveBeenCalledWith("total");
        expect(wrapper.findAll("thead th").map((th) => th.text())).toEqual(["品番・SKU", "最新", "10/4 調査なし", "10/5 月", "10/6 火", "10/7 推定"]);
        const cells = wrapper.findAll('[data-testid="actual-cell"]');
        expect(cells.map((cell) => cell.text())).toEqual(["—", "12", "0", "0"]);
        expect(cells[0]!.attributes("title")).toBe("在庫の記録より前のため、推定できません");
        expect(cells[2]!.classes()).toContain("bg-red-100");
        // 推定在庫は実際の在庫と見分け、根拠をホバーで出す（K-087）
        expect(cells[3]!.attributes("data-estimated")).toBe("true");
        expect(cells[3]!.classes()).toContain("italic");
        expect(cells[3]!.attributes("title")).toBe("推定：10/6(火)の在庫 0 − 10/6(火)の販売数 0");
        expect(wrapper.find('[data-testid="sku-summary"]').text()).toBe("0");
        expect(wrapper.find('[data-testid="description"]').text()).toContain("6区分の合計");
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("2026/10/04〜2026/10/07 のうち 2日に在庫を取得");
        expect(wrapper.find('[data-testid="legend-estimated"]').text()).toContain("推定");

        await wrapper.find('[data-testid="scope-boss"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ scope: "boss" });
        expect(fetchStockActuals).toHaveBeenLastCalledWith("boss");
    });
});
