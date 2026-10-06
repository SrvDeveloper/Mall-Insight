import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import InventoryImportView from "@/views/InventoryImportView.vue";
import { fetchInventoryDates } from "@/api/inventory";
import type { InventoryDate, Paginated } from "@/types/api";

vi.mock("@/api/inventory", () => ({
    fetchInventoryDates: vi.fn(),
    fetchLatestZeroStockViewSync: vi.fn().mockResolvedValue({ latest: null, last_succeeded: null }),
    runZeroStockViewSync: vi.fn(),
}));

function page(data: InventoryDate[]): Paginated<InventoryDate> {
    return {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: { current_page: 1, from: data.length ? 1 : null, last_page: 1, per_page: 31, to: data.length || null, total: data.length },
    };
}

async function mountView() {
    const wrapper = mount(InventoryImportView);
    await flushPromises();
    return wrapper;
}

describe("InventoryImportView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchInventoryDates).mockReset();
        vi.useFakeTimers({ toFake: ["Date"] });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("lists stored stock dates and reports today's inventory as fresh", async () => {
        vi.setSystemTime(new Date("2026-10-06T10:30:00+09:00"));
        vi.mocked(fetchInventoryDates).mockResolvedValue(
            page([
                { stock_date: "2026-10-06", sku_count: 284, fetched_at: "2026-10-06T10:00:02+09:00" },
                { stock_date: "2026-10-05", sku_count: 256, fetched_at: "2026-10-06T10:00:02+09:00" },
            ]),
        );

        const wrapper = await mountView();

        expect(wrapper.text()).toContain("最新の在庫基準日は 2026/10/06(火)（今日）です。");
        expect(wrapper.text()).not.toContain("在庫が古くなっています。");
        const rows = wrapper.findAll("tbody tr");
        expect(rows[0].text()).toContain("2026/10/06(火)");
        expect(rows[0].text()).toContain("284");
    });

    it("does not warn across a weekend but warns once the latest stock date is 4 days old", async () => {
        vi.mocked(fetchInventoryDates).mockResolvedValue(page([{ stock_date: "2026-10-02", sku_count: 256, fetched_at: "2026-10-02T10:00:00+09:00" }]));

        vi.setSystemTime(new Date("2026-10-05T09:00:00+09:00"));
        const mondayMorning = await mountView();
        vi.setSystemTime(new Date("2026-10-06T09:00:00+09:00"));
        const tuesdayMorning = await mountView();

        expect(mondayMorning.text()).toContain("（3日前）");
        expect(mondayMorning.text()).not.toContain("在庫が古くなっています。");
        expect(tuesdayMorning.text()).toContain("（4日前）です。");
        expect(tuesdayMorning.text()).toContain("在庫が古くなっています。");
    });

    it("explains how to get inventory when nothing has been fetched yet", async () => {
        vi.mocked(fetchInventoryDates).mockResolvedValue(page([]));

        const wrapper = await mountView();

        expect(wrapper.text()).toContain("在庫がまだありません。");
    });
});
