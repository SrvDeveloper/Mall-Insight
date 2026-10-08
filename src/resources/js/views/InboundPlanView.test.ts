import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import InboundPlanView from "@/views/InboundPlanView.vue";
import { createInboundPlan, deleteInboundPlan, fetchInboundPlans, setInboundPlanReceived, updateInboundPlan } from "@/api/inboundPlans";
import { fetchAllItems } from "@/api/items";
import { fetchItemForecast } from "@/api/demandForecasts";
import type { InboundPlan, InboundPlanPage, Item, SkuForecast } from "@/types/api";

vi.mock("@/api/inboundPlans", () => ({ fetchInboundPlans: vi.fn(), createInboundPlan: vi.fn(), updateInboundPlan: vi.fn(), deleteInboundPlan: vi.fn(), setInboundPlanReceived: vi.fn() }));
vi.mock("@/api/items", () => ({ fetchAllItems: vi.fn() }));
vi.mock("@/api/demandForecasts", () => ({ fetchItemForecast: vi.fn() }));

const item: Item = {
    id: 1,
    item_no: "fisi-05",
    brand: "SHIORI",
    category: "老眼鏡",
    parent_asin: null,
    status: "active",
    status_label: "有効",
    crosswalker_updated_at: null,
    skus: [
        { id: 10, sku_code: "fisi-05-1-10", child_asin: "B0A", status: "active", status_label: "有効", tq_item_no: null, tq_color_no: null, tq_size: null },
        { id: 11, sku_code: "fisi-05-1-15", child_asin: "B0B", status: "active", status_label: "有効", tq_item_no: null, tq_color_no: null, tq_size: null },
    ],
};

function plan(overrides: Partial<InboundPlan> = {}): InboundPlan {
    return {
        id: 1,
        item_no: "fisi-05",
        brand: "SHIORI",
        category: "老眼鏡",
        exists_in_crosswalker: true,
        arrival_month: "2026-11",
        quantity: 1210,
        allocated_quantity: 300,
        unallocated_quantity: 910,
        note: "PO-001",
        received_at: null,
        is_overdue: false,
        allocations: [{ sku_id: 10, sku_code: "fisi-05-1-10", quantity: 300 }],
        ...overrides,
    };
}

const SUMMARY = { pending_count: 2, pending_quantity: 2420, unallocated_quantity: 2120, overdue_count: 1, received_count: 3 };

function page(data: InboundPlan[]): InboundPlanPage {
    return {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: data.length, total: data.length, summary: SUMMARY },
    };
}

async function mountView() {
    const wrapper = mount(InboundPlanView, { attachTo: document.body });
    await flushPromises();
    return wrapper;
}

describe("InboundPlanView", () => {
    beforeEach(() => {
        vi.mocked(fetchInboundPlans)
            .mockReset()
            .mockResolvedValue(page([plan(), plan({ id: 2, arrival_month: "2026-09", is_overdue: true, allocations: [], allocated_quantity: 0, unallocated_quantity: 1210 })]));
        vi.mocked(fetchAllItems).mockReset().mockResolvedValue([item]);
        vi.mocked(createInboundPlan).mockReset();
        vi.mocked(updateInboundPlan).mockReset();
        vi.mocked(deleteInboundPlan).mockReset();
        vi.mocked(setInboundPlanReceived).mockReset();
        vi.mocked(fetchItemForecast).mockReset();
    });

    it("lists plans by month with their allocations, unallocated quantities and overdue ones", async () => {
        const wrapper = await mountView();

        const months = wrapper.findAll('[data-testid="month-row"]');
        expect(months.map((row) => row.text())).toEqual(["2026年11月1件・1,210個", "2026年9月1件・1,210個予定月を過ぎています"]);
        const rows = wrapper.findAll('[data-testid="plan-row"]');
        expect(rows[0]!.text()).toContain("1,210");
        expect(rows[0]!.find('[data-testid="unallocated"]').text()).toContain("未割り振り 910");
        // SKUごとの割り振りの内訳は一覧に出さない（編集の画面で見る）
        expect(rows[0]!.text()).not.toContain("fisi-05-1-10");
        expect(rows[1]!.text()).toContain("予定月を過ぎています");
        wrapper.unmount();
    });

    it("shows a summary of pending plans and switches to include received ones", async () => {
        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="summary-pending"]').text()).toBe("入荷前の入荷予定2件合計 2,420個");
        expect(wrapper.find('[data-testid="summary-unallocated"]').text()).toContain("2,120個");
        expect(wrapper.find('[data-testid="summary-overdue"]').text()).toContain("入荷済みにするか、入荷予定月を直してください");
        expect(wrapper.find('[data-testid="filter-all"]').text()).toBe("入荷済みも含む 5");

        await wrapper.find('[data-testid="filter-all"]').trigger("click");
        await flushPromises();

        expect(fetchInboundPlans).toHaveBeenLastCalledWith({ keyword: undefined, include_received: true, page: 1 });
        wrapper.unmount();
    });

    it("searches by item number after typing stops", async () => {
        vi.useFakeTimers();
        try {
            const wrapper = await mountView();

            await wrapper.find('input[type="search"]').setValue("fisi");
            expect(fetchInboundPlans).toHaveBeenCalledTimes(1);
            await vi.advanceTimersByTimeAsync(300);

            expect(fetchInboundPlans).toHaveBeenLastCalledWith({ keyword: "fisi", include_received: false, page: 1 });
            wrapper.unmount();
        } finally {
            vi.useRealTimers();
        }
    });

    it("registers a plan with allocations to some of the item's skus", async () => {
        vi.mocked(createInboundPlan).mockResolvedValue(plan());
        const wrapper = await mountView();

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "入荷予定を追加")!
            .trigger("click");
        const dialog = wrapper.find('[role="dialog"]');
        await dialog.find('[data-testid="item-select"]').setValue("fisi-05");
        await dialog.find('[data-testid="arrival-month"]').setValue("2026-11");
        await dialog.find('[data-testid="quantity"]').setValue("1210");
        await dialog.find('input[aria-label="fisi-05-1-10 に割り振る数"]').setValue("300");
        expect(dialog.find('[data-testid="allocation-summary"]').text()).toContain("割り振り 300 ／ 1,210");
        expect(dialog.find('[data-testid="allocation-summary"]').text()).toContain("（未割り振り 910）");
        await dialog.find("form").trigger("submit");
        await flushPromises();

        expect(createInboundPlan).toHaveBeenCalledWith({ item_no: "fisi-05", arrival_month: "2026-11", quantity: 1210, note: null, allocations: [{ sku_id: 10, quantity: 300 }] });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.text()).toContain("fisi-05 の 2026年11月 の入荷予定を保存しました。");
        expect(fetchInboundPlans).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it("allocates the rest automatically by the forecast and keeps what was entered", async () => {
        const forecast = (skuId: number, baseAverage: number | null) => ({ sku_id: skuId, base_average: baseAverage }) as SkuForecast;
        vi.mocked(fetchItemForecast).mockResolvedValue([forecast(10, 1.75), forecast(11, 0.5)]);
        const wrapper = await mountView();
        await wrapper
            .findAll('[data-testid="plan-row"]')[0]!
            .findAll("button")
            .find((button) => button.text() === "編集")!
            .trigger("click");
        const dialog = wrapper.find('[role="dialog"]');
        const input = (code: string) => dialog.find(`input[aria-label="${code} に割り振る数"]`).element as HTMLInputElement;

        // 1,210 のうち 300 は fisi-05-1-10 に割り振り済み。残り 910 を 1.75 : 0.5 で分けると 708 と 202
        await dialog.find('[data-testid="auto-allocate"]').trigger("click");
        await flushPromises();

        expect(fetchItemForecast).toHaveBeenCalledWith("fisi-05");
        expect(input("fisi-05-1-10").value).toBe("1008");
        expect(input("fisi-05-1-15").value).toBe("202");
        expect(dialog.find('[data-testid="allocation-summary"]').text()).toContain("割り振り 1,210 ／ 1,210");
        expect(dialog.find('[data-testid="auto-allocate-note"]').text()).toBe("残り 910 を需要予測の比率で割り振りました。");
        expect(dialog.find('[data-testid="auto-allocate"]').attributes("disabled")).toBeDefined();

        await dialog.find('[data-testid="clear-allocations"]').trigger("click");
        expect(input("fisi-05-1-10").value).toBe("");
        wrapper.unmount();
    });

    it("tells when no SKU has a forecast to allocate by", async () => {
        vi.mocked(fetchItemForecast).mockResolvedValue([{ sku_id: 10, base_average: null } as SkuForecast]);
        const wrapper = await mountView();
        await wrapper
            .findAll('[data-testid="plan-row"]')[0]!
            .findAll("button")
            .find((button) => button.text() === "編集")!
            .trigger("click");

        await wrapper.find('[data-testid="auto-allocate"]').trigger("click");
        await flushPromises();

        expect(wrapper.find('[data-testid="auto-allocate-note"]').text()).toBe("需要予測の出せるSKUが無いため、自動で割り振れません。");
        expect((wrapper.find('input[aria-label="fisi-05-1-10 に割り振る数"]').element as HTMLInputElement).value).toBe("300");
        wrapper.unmount();
    });

    it("does not allow saving allocations over the planned quantity", async () => {
        const wrapper = await mountView();

        await wrapper
            .findAll('[data-testid="plan-row"]')[0]!
            .findAll("button")
            .find((button) => button.text() === "編集")!
            .trigger("click");
        const dialog = wrapper.find('[role="dialog"]');
        expect((dialog.find('input[aria-label="fisi-05-1-10 に割り振る数"]').element as HTMLInputElement).value).toBe("300");
        await dialog.find('input[aria-label="fisi-05-1-15 に割り振る数"]').setValue("1000");

        expect(dialog.find('[data-testid="allocation-summary"]').text()).toContain("入荷予定数を超えています");
        expect(dialog.find('button[type="submit"]').attributes("disabled")).toBeDefined();
        wrapper.unmount();
    });

    it("marks a plan as received", async () => {
        vi.mocked(setInboundPlanReceived).mockResolvedValue(plan({ received_at: "2026-10-07T09:00:00+09:00" }));
        const wrapper = await mountView();

        await wrapper
            .findAll('[data-testid="plan-row"]')[0]!
            .findAll("button")
            .find((button) => button.text() === "入荷済みにする")!
            .trigger("click");
        await flushPromises();

        expect(setInboundPlanReceived).toHaveBeenCalledWith(1, true);
        expect(wrapper.text()).toContain("入荷済みにしました。在庫推移には入りません。");
        wrapper.unmount();
    });

    it("deletes a plan after confirmation", async () => {
        vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
        vi.mocked(deleteInboundPlan).mockResolvedValue();
        const wrapper = await mountView();
        const deleteButton = () =>
            wrapper
                .findAll('[data-testid="plan-row"]')[0]!
                .findAll("button")
                .find((button) => button.text() === "削除")!;

        await deleteButton().trigger("click");
        expect(deleteInboundPlan).not.toHaveBeenCalled();
        await deleteButton().trigger("click");
        await flushPromises();

        expect(deleteInboundPlan).toHaveBeenCalledWith(1);
        wrapper.unmount();
    });
});
