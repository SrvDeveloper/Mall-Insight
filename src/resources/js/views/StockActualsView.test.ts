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
                skus: [{ sku_id: 1, sku_code: "fl-01-1-10", status: "active", status_label: "有効", not_sold: false, quantities: [null, 12, 0] }],
            },
        ],
        meta: {
            ...meta,
            scope: "total",
            scope_warehouses: [{ warehouse: "free_stock", label: "フリー在庫" }],
            dates: [
                { date: "2026-10-04", has_data: false },
                { date: "2026-10-05", has_data: true },
                { date: "2026-10-06", has_data: true },
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

        expect(wrapper.find("h1").text()).toBe("在庫実績");
        expect(fetchStockActuals).toHaveBeenCalledWith("total");
        expect(wrapper.findAll("thead th").map((th) => th.text())).toEqual(["品番・SKU", "最新", "10/4 調査なし", "10/5 月", "10/6 火"]);
        const cells = wrapper.findAll('[data-testid="actual-cell"]');
        expect(cells.map((cell) => cell.text())).toEqual(["—", "12", "0"]);
        expect(cells[2]!.classes()).toContain("bg-red-100");
        expect(wrapper.find('[data-testid="description"]').text()).toContain("6区分の合計");
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("2026/10/04〜2026/10/06 のうち 2日に在庫を取得");

        await wrapper.find('[data-testid="scope-boss"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ scope: "boss" });
        expect(fetchStockActuals).toHaveBeenLastCalledWith("boss");
    });
});
