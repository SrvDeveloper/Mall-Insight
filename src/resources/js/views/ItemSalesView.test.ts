import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import ItemSalesView from "@/views/ItemSalesView.vue";
import { fetchSalesActuals } from "@/api/actuals";
import type { SalesActualsResponse } from "@/types/api";

vi.mock("@/api/actuals", () => ({ fetchSalesActuals: vi.fn(), fetchStockActuals: vi.fn() }));

const meta = { calculated_at: "2026-10-07T10:00:00+09:00", scope_label: "全体", selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 } };

function salesResponse(scope: "total" | "amazon" = "total"): SalesActualsResponse {
    return {
        data: [
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [
                    { sku_id: 1, sku_code: "fl-01-1-10", status: "active", status_label: "有効", not_sold: false, quantities: [null, 3, 5], amounts: [null, 16500, 27500] },
                    { sku_id: 2, sku_code: "fl-01-1-15", status: "inactive", status_label: "廃番", not_sold: scope === "amazon", quantities: [null, 1, 0], amounts: [null, 5500, 0] },
                ],
            },
        ],
        meta: {
            ...meta,
            scope,
            scope_label: scope === "amazon" ? "Amazon" : "全体",
            months: [
                { month: "2026-08", coverage: "none" },
                { month: "2026-09", coverage: "full" },
                { month: "2026-10", coverage: "partial" },
            ],
            channels: [
                { channel: "boss", label: "BOSS", sales_data_from: "2026-09-01", sales_data_to: "2026-10-06" },
                { channel: "amazon", label: "Amazon", sales_data_from: null, sales_data_to: null },
            ],
        },
    };
}

async function mountView(path = "/item-sales") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: ItemSalesView }] });
    await router.push(path);
    const wrapper = mount(ItemSalesView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("ItemSalesView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesActuals).mockReset().mockResolvedValue(salesResponse());
    });

    it("shows monthly sales with months without data as a dash and the period total", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("品番別売上");
        expect(fetchSalesActuals).toHaveBeenCalledWith("total");
        const headers = wrapper.findAll("thead th").map((th) => th.text());
        expect(headers).toEqual(["品番・SKU", "期間計", "26/8 取込なし", "26/9", "26/10 今月"]);
        const row = wrapper.findAll('[data-testid="sku-row"]')[0]!;
        expect(row.findAll('[data-testid="actual-cell"]').map((cell) => cell.text())).toEqual(["—", "3", "5"]);
        expect(row.find('[data-testid="sku-summary"]').text()).toBe("8");
        expect(wrapper.find('[data-testid="item-summary"]').text()).toBe("9");
        expect(wrapper.findAll('[data-testid="sku-row"]')[1]!.text()).toContain("廃番");
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("BOSS 2026/09/01〜2026/10/06・Amazon 未取込");
    });

    it("switches to a mall, keeping it in the URL", async () => {
        const { wrapper, router } = await mountView();
        vi.mocked(fetchSalesActuals).mockResolvedValue(salesResponse("amazon"));

        await wrapper.find('[data-testid="scope-amazon"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ scope: "amazon" });
        expect(fetchSalesActuals).toHaveBeenLastCalledWith("amazon");
        expect(wrapper.find('[data-testid="not-sold"]').text()).toBe("Amazonでは販売していません");
        expect(wrapper.find('[data-testid="range-note"]').text()).toBe("Amazon 未取込");
    });

    it("draws the graph and filters by keyword", async () => {
        const { wrapper } = await mountView("/item-sales?view=graph");

        expect(wrapper.findAll('[data-testid="graph-tile"]')).toHaveLength(2);
        await wrapper.find('input[type="search"]').setValue("1-15");
        expect(wrapper.findAll('[data-testid="graph-tile"]')).toHaveLength(1);
    });

    it("asks to confirm target items first", async () => {
        vi.mocked(fetchSalesActuals).mockResolvedValue({ ...salesResponse(), data: [], meta: { ...salesResponse().meta, selection: null } });
        const { wrapper } = await mountView();

        expect(wrapper.text()).toContain("対象品番がまだ確定されていません。");
    });

    it("switches to the sales amounts in thousands of yen, keeping it in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="measure-amount"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ measure: "amount" });
        expect(fetchSalesActuals).toHaveBeenCalledTimes(1);
        expect(wrapper.findAll("thead th")[1]!.text()).toBe("期間計（千円）");
        const row = wrapper.findAll('[data-testid="sku-row"]')[0]!;
        expect(row.findAll('[data-testid="actual-cell"]').map((cell) => cell.text())).toEqual(["—", "17", "28"]);
        expect(row.find('[data-testid="sku-summary"]').text()).toBe("44");
        expect(wrapper.find('[data-testid="item-summary"]').text()).toBe("50");
        expect(wrapper.find('[data-testid="amount-note"]').text()).toContain("税込");
    });
});
