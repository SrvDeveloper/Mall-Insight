import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import InboundPlanView from "@/views/InboundPlanView.vue";
import { createInboundPlan, deleteInboundPlan, fetchInboundPlans, setInboundPlanReceived, updateInboundPlan } from "@/api/inboundPlans";
import { fetchAllItems } from "@/api/items";
import type { InboundPlan, Item, Paginated } from "@/types/api";

vi.mock("@/api/inboundPlans", () => ({ fetchInboundPlans: vi.fn(), createInboundPlan: vi.fn(), updateInboundPlan: vi.fn(), deleteInboundPlan: vi.fn(), setInboundPlanReceived: vi.fn() }));
vi.mock("@/api/items", () => ({ fetchAllItems: vi.fn() }));

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

function page(data: InboundPlan[]): Paginated<InboundPlan> {
    return { data, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, from: 1, last_page: 1, per_page: 50, to: data.length, total: data.length } };
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
    });

    it("lists plans with their allocations, unallocated quantities and overdue ones", async () => {
        const wrapper = await mountView();

        const rows = wrapper.findAll('[data-testid="plan-row"]');
        expect(rows[0]!.text()).toContain("2026年11月");
        expect(rows[0]!.text()).toContain("1,210");
        expect(rows[0]!.find('[data-testid="unallocated"]').text()).toContain("未割り振り 910");
        expect(rows[0]!.text()).toContain("fisi-05-1-10 300");
        expect(rows[1]!.text()).toContain("予定月を過ぎています");
        wrapper.unmount();
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
