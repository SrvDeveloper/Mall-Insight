import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import InventoryTrendView from "@/views/InventoryTrendView.vue";
import { fetchInventoryTrends } from "@/api/inventoryTrends";
import type { InventoryTrendResponse, MonthTrend, SkuTrend } from "@/types/api";

vi.mock("@/api/inventoryTrends", () => ({ fetchInventoryTrends: vi.fn() }));

const MONTHS = ["2026-10", "2026-11", "2026-12", "2027-01", "2027-02", "2027-03", "2027-04", "2027-05", "2027-06", "2027-07", "2027-08", "2027-09"];

function months(endings: number[], overrides: Partial<MonthTrend>[] = []): MonthTrend[] {
    return MONTHS.map((month, index) => ({
        month,
        days: index === 0 ? 25 : 30,
        opening_stock: index === 0 ? 100 : Math.max(0, endings[index - 1]!),
        demand: 30,
        inbound: 0,
        provisional_inbound: 0,
        ending_stock: Math.max(0, endings[index]!),
        shortfall: Math.max(0, -endings[index]!),
        ...overrides[index],
    }));
}

function sku(overrides: Partial<SkuTrend>): SkuTrend {
    return {
        sku_id: 1,
        sku_code: "fl-01-1-10",
        status: "calculated",
        status_label: "計算済み",
        opening_stock: 100,
        stock_by_warehouse: [
            { warehouse: "amazon_fba", label: "Amazon FBA", quantity: 60 },
            { warehouse: "boss_own", label: "BOSS自社倉庫", quantity: 40 },
        ],
        average_daily: 1,
        is_partial_forecast: false,
        forecast_reason_label: null,
        warning: "none",
        warning_label: "警告なし",
        first_shortage_month: null,
        months: months([75, 45, 15, -15, -45, -75, -105, -135, -165, -195, -225, -255]),
        ...overrides,
    };
}

const shortage = sku({ sku_id: 1, sku_code: "fl-01-1-10", warning: "shortage", warning_label: "欠品警告", first_shortage_month: "2027-01" });
const plenty = sku({
    sku_id: 2,
    sku_code: "fl-01-1-15",
    months: months([500, 480, 460, 440, 420, 400, 380, 360, 340, 320, 300, 280], [{}, { inbound: 50, provisional_inbound: 100 }]),
});
const noStock = sku({
    sku_id: 3,
    sku_code: "fl-01-1-20",
    status: "no_stock",
    status_label: "在庫の記録が無いため、在庫推移を計算できません",
    opening_stock: null,
    stock_by_warehouse: null,
    months: null,
});

function response(overrides: Partial<InventoryTrendResponse["meta"]> = {}): InventoryTrendResponse {
    return {
        data: [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", unassignable_inbound: 0, skus: [shortage, plenty, noStock] }],
        meta: {
            calculated_at: "2026-10-07T10:00:00+09:00",
            stock_date: "2026-10-07",
            forecast_from: "2026-10-07",
            sales_channels: [
                { channel: "boss", label: "BOSS", sales_data_to: "2026-10-06" },
                { channel: "amazon", label: "Amazon", sales_data_to: "2026-10-06" },
            ],
            selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 },
            settings: { check_month_offset: 6 },
            check_month: "2027-04",
            overdue_inbound_count: 0,
            ...overrides,
        },
    };
}

async function mountView(path = "/inventory-trends") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: InventoryTrendView }] });
    await router.push(path);
    const wrapper = mount(InventoryTrendView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("InventoryTrendView", () => {
    beforeEach(() => {
        vi.mocked(fetchInventoryTrends).mockReset().mockResolvedValue(response());
    });

    it("shows the basis of the calculation as a strip of facts", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find('[data-testid="fact-stock"]').text()).toContain("2026年10月7日");
        expect(wrapper.find('[data-testid="fact-stock"]').text()).toContain("6区分の合計");
        expect(wrapper.find('[data-testid="fact-forecast"]').text()).toContain("BOSS・Amazon");
        expect(wrapper.find('[data-testid="fact-forecast"]').text()).toContain("BOSS 10/6・Amazon 10/6 まで");
        expect(wrapper.find('[data-testid="fact-check"]').text()).toContain("2027年4月");
        expect(wrapper.find('[data-testid="fact-selection"]').text()).toContain("1品番・3 SKU");
        expect(wrapper.find('[data-testid="fact-warning"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="calculated-at"]').text()).toContain("2026/10/07 10:00");
    });

    it("warns about old stock and overdue inbound plans", async () => {
        vi.mocked(fetchInventoryTrends).mockResolvedValue(response({ stock_date: "2026-10-01", overdue_inbound_count: 2 }));

        const { wrapper } = await mountView();

        expect(wrapper.find('[data-testid="fact-warning"]').text()).toContain("6日前の在庫です。");
        expect(wrapper.find('[data-testid="overdue-inbound"]').text()).toContain("入荷予定月を過ぎた入荷予定が2件あり");
    });

    it("shows the table by default with shortages and zero stock as round pills", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find('[data-testid="trend-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="view-table"]').attributes("aria-selected")).toBe("true");
        const headers = wrapper.findAll("thead tr")[1]!.findAll("th");
        expect(headers[8]!.text()).toBe("27/4 判定");
        expect(
            wrapper
                .findAll("thead tr")[0]!
                .findAll("th")
                .map((th) => th.text()),
        ).toEqual(["", "2026", "2027"]);

        const cells = wrapper.findAll('[data-testid="sku-row"]')[0]!.findAll('[data-testid="month-cell"]');
        expect(cells[2]!.text()).toBe("15");
        expect(cells[3]!.text()).toBe("−15");
        expect(cells[3]!.classes()).toEqual(expect.arrayContaining(["rounded-full", "bg-red-100", "text-red-700"]));
        expect(wrapper.find('[data-testid="item-shortage"]').text()).toBe("欠品 1/2");
    });

    it("shows a shortfall under one unit as 0 instead of -0", async () => {
        const tiny = sku({ months: months([75, 45, 15, 0, 0, 0, 0, 0, 0, 0, 0, 0], [{}, {}, {}, { ending_stock: 0, shortfall: 0.3 }]) });
        vi.mocked(fetchInventoryTrends).mockResolvedValue({ ...response(), data: [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", unassignable_inbound: 0, skus: [tiny] }] });

        const { wrapper } = await mountView();

        const cell = wrapper.find('[data-testid="sku-row"]').findAll('[data-testid="month-cell"]')[3]!;
        expect(cell.text()).toBe("0");
        expect(cell.classes()).toContain("bg-amber-100");
        expect(wrapper.find('[data-testid="item-row"]').findAll("td")[4]!.text()).toBe("0");
    });

    it("marks months with inbound plans on the item row and explains SKUs that cannot be calculated", async () => {
        const { wrapper } = await mountView();

        const icon = wrapper.find('[data-testid="item-row"]').findAll("td")[2]!.find('[data-testid="inbound-icon"]');
        expect(icon.attributes("aria-label")).toBe("2026年11月 入荷予定 150（うち仮の割り振り 100）");
        expect(wrapper.findAll('[data-testid="sku-row"]')[2]!.find('[data-testid="status"]').text()).toBe("在庫の記録が無いため、在庫推移を計算できません");
    });

    it("shows the month's breakdown in a tooltip when hovering over the cell", async () => {
        const { wrapper } = await mountView();
        vi.useFakeTimers();

        try {
            // 数字の札ではなく、欄全体にマウスを乗せても出る
            await wrapper.findAll('[data-testid="sku-row"]')[1]!.findAll('[data-testid="month-td"]')[1]!.trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(200);

            const tooltip = wrapper.find('[role="tooltip"]');
            expect(tooltip.text()).toContain("fl-01-1-15");
            expect(tooltip.text()).toContain("2026年11月");
            expect(tooltip.text()).toContain("+150");
            expect(tooltip.text()).toContain("（仮100）");
            expect(tooltip.find('[data-testid="tooltip-shortfall"]').exists()).toBe(false);

            await wrapper.findAll('[data-testid="sku-row"]')[1]!.findAll('[data-testid="month-td"]')[1]!.trigger("mouseleave");
            await wrapper.findAll('[data-testid="sku-row"]')[0]!.findAll('[data-testid="month-td"]')[6]!.trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(200);
            const shortTooltip = wrapper.find('[role="tooltip"]');
            expect(shortTooltip.text()).toContain("判定");
            expect(shortTooltip.find('[data-testid="tooltip-shortfall"]').text()).toBe("足りない 105");

            await wrapper.findAll('[data-testid="sku-row"]')[0]!.findAll('[data-testid="month-td"]')[6]!.trigger("mouseleave");
            await wrapper.find('[data-testid="stock"]').trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(200);
            const stockTooltip = wrapper.find('[role="tooltip"]').text();
            expect(stockTooltip).toContain("Amazon FBA");
            expect(stockTooltip).toContain("BOSS自社倉庫");
            expect(stockTooltip).toContain("合計 100");
            expect(stockTooltip).toContain("約3.3か月分");
        } finally {
            vi.useRealTimers();
        }
    });

    it("switches to the timeline and keeps the choice in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="view-timeline"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ view: "timeline" });
        expect(wrapper.find('[data-testid="trend-table"]').exists()).toBe(false);
        const rows = wrapper.findAll('[data-testid="timeline-sku-row"]');
        const blocks = rows[0]!.findAll('[data-testid="timeline-block"]');
        expect(blocks).toHaveLength(12);
        // 平均日販1・月末在庫75（約2.5か月分）、15（0.5か月分）、足りない月
        expect(blocks.map((block) => block.attributes("data-cover")).slice(0, 4)).toEqual(["some", "limited", "low", "shortage"]);
        expect(rows[0]!.find('[data-testid="timeline-status"]').text()).toBe("1月から欠品");
        expect(rows[1]!.find('[data-testid="timeline-status"]').text()).toBe("12か月もつ");
        expect(rows[0]!.text()).toContain("在庫100・3.3か月分");
        expect(wrapper.find('[data-testid="timeline-inbound"]').text()).toBe("+150");
        expect(wrapper.find('[data-testid="legend"]').text()).toContain("在庫が持つ月数");
    });

    it("shows small graphs per SKU when the graph view is chosen", async () => {
        const { wrapper } = await mountView("/inventory-trends?view=graph");

        expect(wrapper.find('[data-testid="view-graph"]').attributes("aria-selected")).toBe("true");
        const tiles = wrapper.findAll('[data-testid="graph-tile"]');
        expect(tiles).toHaveLength(3);
        expect(tiles[0]!.find('[data-testid="graph-status"]').text()).toBe("1月から欠品");
        expect(tiles[0]!.classes()).toContain("border-red-200");
        expect(tiles[1]!.findAll('[data-testid="graph-inbound"]')).toHaveLength(1);
        expect(tiles[2]!.text()).toContain("在庫の記録が無いため、在庫推移を計算できません");
        expect(wrapper.find('[data-testid="graph-item"]').text()).toContain("11月 +150");
    });

    it("automatically lays out graphs even when a previous column preference is saved", async () => {
        const storageKey = "mall-insight.inventory-trend.graph-columns";
        window.localStorage.setItem(storageKey, JSON.stringify({ "fl-01": 3 }));
        try {
            const { wrapper } = await mountView("/inventory-trends?view=graph");

            expect(wrapper.find('[data-testid="graph-columns"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="graph-grid"]').attributes("style")).toContain("auto-fill");
            expect(wrapper.findAll('[data-testid="graph-tile"]')).toHaveLength(3);
            wrapper.unmount();
        } finally {
            window.localStorage.removeItem(storageKey);
        }
    });

    it("filters by warning and by keyword in every view", async () => {
        const { wrapper } = await mountView();
        const codes = () => wrapper.findAll('[data-testid="sku-row"]').map((row) => row.find("td").text());
        const filterButton = (label: string) => wrapper.findAll('[aria-label="絞り込み"] button').find((button) => button.text().startsWith(label))!;

        expect(filterButton("欠品警告あり").text()).toBe("欠品警告あり 1");
        await filterButton("欠品警告あり").trigger("click");
        expect(codes()).toEqual(["fl-01-1-10"]);
        await filterButton("すべて").trigger("click");
        await wrapper.find('input[type="search"]').setValue("1-20");
        expect(codes()).toEqual(["fl-01-1-20"]);

        await wrapper.find('[data-testid="view-graph"]').trigger("click");
        await flushPromises();
        expect(wrapper.findAll('[data-testid="graph-tile"]')).toHaveLength(1);
    });
});
