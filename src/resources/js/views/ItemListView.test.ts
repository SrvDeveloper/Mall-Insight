import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import ItemListView from "@/views/ItemListView.vue";
import { fetchItems } from "@/api/items";
import type { Item, Paginated } from "@/types/api";

vi.mock("@/api/items", () => ({ fetchItems: vi.fn() }));
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
        { id: 11, sku_code: "fisi-05-1-15", child_asin: null, status: "inactive", status_label: "無効", tq_item_no: "FISI05", tq_color_no: "1", tq_size: "15" },
    ],
};

function page(data: Item[]): Paginated<Item> {
    return {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: { current_page: 1, from: data.length ? 1 : null, last_page: 1, per_page: 25, to: data.length || null, total: data.length },
    };
}

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
        expect(skuTable.text()).toContain("無効");
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
});
