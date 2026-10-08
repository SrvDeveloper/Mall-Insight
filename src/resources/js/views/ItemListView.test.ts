import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import ItemListView from "@/views/ItemListView.vue";
import { fetchItems } from "@/api/items";
import { fetchUnregisteredSkus } from "@/api/unregisteredSkus";
import type { Item, Paginated, UnregisteredSku, UnregisteredSkuPage } from "@/types/api";

vi.mock("@/api/items", () => ({ fetchItems: vi.fn() }));
vi.mock("@/api/unregisteredSkus", () => ({ fetchUnregisteredSkus: vi.fn() }));
vi.mock("@/api/crosswalkerSyncs", () => ({
    fetchLatestCrossWalkerSync: vi.fn().mockResolvedValue({ latest: null, last_succeeded: null }),
    runCrossWalkerSync: vi.fn(),
}));

const item: Item = {
    id: 1,
    item_no: "fisi-05",
    brand: "SHIORI",
    category: "老眼鏡",
    parent_asin: null,
    status: "active",
    status_label: "有効",
    crosswalker_updated_at: "2026-09-08T07:08:16+00:00",
    skus: [
        { id: 10, sku_code: "fisi-05-1-10", child_asin: "B09EXAMPLE1", status: "active", status_label: "有効", tq_item_no: "FISI05", tq_color_no: "1", tq_size: "10" },
        { id: 11, sku_code: "fisi-05-1-15", child_asin: null, status: "inactive", status_label: "廃番", tq_item_no: "FISI05", tq_color_no: "1", tq_size: "15" },
    ],
};

function page<T>(data: T[]): Paginated<T> {
    return {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: { current_page: 1, from: data.length ? 1 : null, last_page: 1, per_page: 25, to: data.length || null, total: data.length },
    };
}

function unregisteredPage(data: UnregisteredSku[]): UnregisteredSkuPage {
    const base = page(data);
    return { ...base, meta: { ...base.meta, per_page: 50, sales_from: "2025-10-08", sales_to: "2026-10-07" } };
}

const unregisteredSku: UnregisteredSku = {
    id: 20,
    sku_code: "hy-6219-blk",
    recent_sales_quantity: 8,
    first_sold_on: "2026-08-19",
    last_sold_on: "2026-08-24",
    first_stocked_on: null,
    last_stocked_on: null,
};

async function mountAt(path: string) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/items", name: "items", component: ItemListView }] });
    await router.push(path);
    const wrapper = mount(ItemListView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("ItemListView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchItems).mockReset();
        vi.mocked(fetchUnregisteredSkus).mockReset().mockResolvedValue(unregisteredPage([]));
    });

    it("shows items with a placeholder for a missing parent ASIN", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([item]));

        const { wrapper } = await mountAt("/items");

        const row = wrapper.find("tbody tr");
        expect(row.text()).toContain("fisi-05");
        expect(row.text()).toContain("未設定");
        expect(row.text()).toContain("2026/09/08 16:08");
        expect(wrapper.text()).toContain("1件中 1–1件");
    });

    it("expands an item to show its SKUs", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([item]));
        const { wrapper } = await mountAt("/items");

        await wrapper.find('button[aria-controls="skus-1"]').trigger("click");

        const skuTable = wrapper.find("#skus-1");
        expect(skuTable.text()).toContain("fisi-05-1-10");
        expect(skuTable.text()).toContain("fisi-05-1-15");
        expect(skuTable.text()).toContain("廃番");
    });

    it("passes the URL query to the API and updates it when filtering by status", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([item]));
        const { wrapper, router } = await mountAt("/items?keyword=fisi&page=2");

        expect(fetchItems).toHaveBeenLastCalledWith({ keyword: "fisi", status: "all", page: 2 });

        const inactiveButton = wrapper.findAll('[aria-label="状態で絞り込み"] button').find((button) => button.text() === "無効");
        await inactiveButton!.trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ keyword: "fisi", status: "inactive" });
        expect(fetchItems).toHaveBeenLastCalledWith({ keyword: "fisi", status: "inactive", page: 1 });
    });

    it("explains that no items are registered yet when the list is empty without filters", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([]));

        const { wrapper } = await mountAt("/items");

        expect(wrapper.text()).toContain("品番がまだ登録されていません。");
    });

    it("reloads the list from the retry action after a failure", async () => {
        const { ApiError } = await import("@/api/client");
        vi.mocked(fetchItems)
            .mockRejectedValueOnce(new ApiError("サーバーに接続できません。", null))
            .mockResolvedValueOnce(page([item]));
        const { wrapper } = await mountAt("/items");
        expect(wrapper.text()).toContain("一覧を表示できませんでした。");

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "再読み込み")!
            .trigger("click");
        await flushPromises();

        expect(fetchItems).toHaveBeenCalledTimes(2);
        expect(wrapper.find("tbody tr").text()).toContain("fisi-05");
    });

    it("shows the number of unregistered SKUs on the tab and lists them when the tab is selected", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([item]));
        vi.mocked(fetchUnregisteredSkus).mockResolvedValue(unregisteredPage([unregisteredSku]));
        const { wrapper, router } = await mountAt("/items?keyword=fisi");

        expect(wrapper.find('[data-testid="unregistered-total"]').text()).toBe("1件");
        expect(wrapper.find("#panel-unregistered").attributes("style")).toContain("display: none");

        await wrapper.find("#tab-unregistered").trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ keyword: "fisi", tab: "unregistered" });
        expect(fetchItems).toHaveBeenCalledTimes(1);
        const panel = wrapper.find("#panel-unregistered");
        expect(panel.attributes("style") ?? "").not.toContain("display: none");
        expect(wrapper.find("#panel-items").attributes("style")).toContain("display: none");
        expect(panel.text()).toContain("2025/10/08 〜 2026/10/07");
        const row = panel.find("tbody tr");
        expect(row.text()).toContain("hy-6219-blk");
        expect(row.text()).toContain("2026/08/19 〜 2026/08/24");
        expect(row.text()).toContain("なし");
    });

    it("says there are no unregistered SKUs when every SKU belongs to an item", async () => {
        vi.mocked(fetchItems).mockResolvedValue(page([item]));

        const { wrapper } = await mountAt("/items?tab=unregistered");

        expect(wrapper.find('[data-testid="unregistered-total"]').text()).toBe("0件");
        expect(wrapper.find("#panel-unregistered").text()).toContain("未登録のSKUはありません。");
    });
});
