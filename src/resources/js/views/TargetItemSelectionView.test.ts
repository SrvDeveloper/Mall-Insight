import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import TargetItemSelectionView from "@/views/TargetItemSelectionView.vue";
import { confirmItemSelection, fetchCurrentItemSelection, fetchItemRanking, fetchItemSelections } from "@/api/itemSelections";
import type { CurrentItemSelection, ItemRank, ItemRanking, ItemSelection, Paginated, SelectionScope } from "@/types/api";

vi.mock("@/api/itemSelections", () => ({ fetchItemRanking: vi.fn(), fetchCurrentItemSelection: vi.fn(), confirmItemSelection: vi.fn(), fetchItemSelections: vi.fn() }));

function rank(item_no: string, sales_rank: number | null, is_candidate: boolean): ItemRank {
    return {
        item_id: item_no.charCodeAt(0),
        item_no,
        brand: "SHIORI",
        category: "老眼鏡",
        status: "active",
        status_label: "有効",
        sku_count: 3,
        sales_amount: sales_rank === null ? null : 10000 - sales_rank,
        sales_quantity: sales_rank === null ? null : 5,
        sales_rank,
        is_candidate,
    };
}

const ranking: ItemRanking = {
    data: [rank("a-01", 1, true), rank("b-01", 2, true), rank("c-01", 3, false), rank("d-01", null, false)],
    meta: { scope: "boss", ranking_from: "2025-10-08", ranking_to: "2026-10-07", candidate_rank: 2, max_items: 3 },
};

function selection(itemNos: string[], confirmedAt: string): ItemSelection {
    return {
        id: 1,
        scope: "boss",
        confirmed_at: confirmedAt,
        confirmed_by: "在庫 担当",
        ranking_from: "2025-10-08",
        ranking_to: "2026-10-07",
        note: null,
        items: itemNos.map((item_no) => ({ item_no, sales_rank: null, sales_amount: null, is_candidate: false, exists_in_crosswalker: item_no !== "gone-01" })),
    };
}

function currentWith(data: ItemSelection | null, scope: SelectionScope = "boss"): CurrentItemSelection {
    return { data, meta: { scope, item_nos: data?.items.map((item) => item.item_no) ?? ["a-01"], uses_candidates: data === null, fiscal_year_start: "2026-04-01" } };
}

async function mountView(path = "/target-items?scope=boss") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: TargetItemSelectionView }] });
    await router.push(path);
    const wrapper = mount(TargetItemSelectionView, { global: { plugins: [router] } });
    await flushPromises();
    return Object.assign(wrapper, { router });
}

function checkedItemNos(wrapper: Awaited<ReturnType<typeof mountView>>): string[] {
    return wrapper
        .findAll('[data-testid="rank-row"]')
        .filter((row) => (row.find('input[type="checkbox"]').element as HTMLInputElement).checked)
        .map((row) => row.find('[data-testid="item-no"]').text());
}

describe("TargetItemSelectionView", () => {
    beforeEach(() => {
        vi.mocked(fetchItemRanking).mockReset().mockResolvedValue(ranking);
        vi.mocked(fetchCurrentItemSelection).mockReset();
        vi.mocked(confirmItemSelection).mockReset();
    });

    it("selects the candidates when nothing has been confirmed yet", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(null));

        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="selection-warning"]').text()).toContain("対象品番がまだ確定されていません。");
        expect(wrapper.find('[data-testid="fact-current"]').text()).toContain("未確定");
        expect(checkedItemNos(wrapper)).toEqual(["a-01", "b-01"]);
        expect(wrapper.findAll('[data-testid="change"]').map((change) => change.text())).toEqual(["対象にする", "対象にする"]);
        expect(wrapper.findAll('[data-testid="rank-row"]').at(-1)!.text()).toContain("実績なし");
        // 上位の候補（2位まで）の下に区切りを引く
        expect(wrapper.text()).toContain("ここまで上位2位");
        expect(wrapper.text()).toContain("2025/10/08 〜 2026/10/07");
    });

    it("starts from the current selection and asks for a review when it was confirmed before this fiscal year", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(selection(["c-01", "gone-01"], "2026-03-31T10:00:00+09:00")));

        const wrapper = await mountView();

        const warning = wrapper.find('[data-testid="selection-warning"]').text();
        expect(warning).toContain("2026年度のBOSSの対象品番がまだ確定されていません。");
        expect(warning).toContain("gone-01");
        expect(wrapper.find('[data-testid="fact-current"]').text()).toContain("2品番2026/03/31 10:00 在庫 担当 が確定");
        expect(wrapper.find('[data-testid="fact-fiscal-year"]').text()).toContain("2026年度の見直し未確定");
        expect(checkedItemNos(wrapper)).toEqual(["c-01"]);
    });

    it("does not ask for a review when the selection was confirmed on or after April 1", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(selection(["a-01"], "2026-03-31T15:00:00+00:00")));

        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="selection-warning"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="fact-fiscal-year"]').text()).toContain("確定済み");
    });

    it("blocks confirming more than the maximum number of items", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(null));
        const wrapper = await mountView();

        await wrapper.findAll('[data-testid="rank-row"]')[2]!.trigger("click");
        expect(wrapper.find('button[type="submit"]').attributes("disabled")).toBeUndefined();
        await wrapper.findAll('[data-testid="rank-row"]')[3]!.trigger("click");

        expect(wrapper.find('[data-testid="selection-summary"]').text()).toContain("3品番以内にしてください。");
        expect(wrapper.find('button[type="submit"]').attributes("disabled")).toBeDefined();
    });

    it("confirms the selected items in ranking order with the note", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(selection(["a-01"], "2026-04-10T10:00:00+09:00")));
        vi.mocked(confirmItemSelection).mockResolvedValue(selection(["a-01", "c-01"], "2026-10-07T10:00:00+09:00"));
        const wrapper = await mountView();

        await wrapper.findAll('[data-testid="rank-row"]')[2]!.trigger("click");
        expect(wrapper.find('[data-testid="selection-summary"]').text()).toContain("追加 1・外す 0");
        expect(wrapper.findAll('[data-testid="change"]').map((change) => change.text())).toEqual(["対象", "追加"]);
        await wrapper.find("#selection-note").setValue(" 新商品が好調 ");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(confirmItemSelection).toHaveBeenCalledWith(["a-01", "c-01"], "新商品が好調", "boss");
        expect(wrapper.text()).toContain("BOSSの対象品番を確定しました（2品番）。");
        expect(wrapper.find('[data-testid="fact-current"]').text()).toContain("2品番2026/10/07 10:00 在庫 担当 が確定");
    });

    it("opens the history in a modal from the current selection and closes it with Escape", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(selection(["a-01", "c-01"], "2026-10-07T10:00:00+09:00")));
        const latest = { ...selection(["a-01", "c-01"], "2026-10-07T10:00:00+09:00"), id: 2, note: "入れ替え", changes: { added: ["c-01"], removed: ["b-01"] } };
        const first = { ...selection(["a-01", "b-01"], "2026-04-10T10:00:00+09:00"), id: 1, confirmed_by: null, changes: null };
        const history: Paginated<ItemSelection> = {
            data: [latest, first],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 2, total: 2 },
        };
        vi.mocked(fetchItemSelections).mockResolvedValue(history);
        const wrapper = await mountView();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

        await wrapper.find('[data-testid="open-history"]').trigger("click");
        await flushPromises();

        const dialog = wrapper.find('[role="dialog"]');
        expect(dialog.exists()).toBe(true);
        const rows = dialog.findAll("table > tbody > tr");
        expect(rows[0]!.text()).toContain("2026/10/07 10:00");
        expect(rows[0]!.text()).toContain("今の対象");
        expect(rows[0]!.find('[data-testid="confirmed-by"]').text()).toBe("在庫 担当");
        expect(rows[0]!.text()).toContain("追加 1・外す 1");
        expect(rows[0]!.text()).toContain("入れ替え");
        expect(rows[1]!.text()).toContain("初回");
        expect(rows[1]!.find('[data-testid="confirmed-by"]').text()).toBe("記録なし");

        await rows[0]!.trigger("click");
        const detail = dialog.find("#selection-2");
        expect(detail.text()).toContain("c-01");
        expect(detail.find('[data-testid="removed"]').text()).toContain("b-01");

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it("filters to the selected items or by keyword and marks items to remove", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(selection(["a-01", "b-01"], "2026-04-10T10:00:00+09:00")));
        const wrapper = await mountView();

        await wrapper.findAll('[data-testid="rank-row"]')[1]!.trigger("click");
        expect(wrapper.findAll('[data-testid="change"]').map((change) => change.text())).toEqual(["対象", "外す"]);

        await wrapper.find('[data-testid="filter-selected"]').trigger("click");
        expect(wrapper.findAll('[data-testid="item-no"]').map((cell) => cell.text())).toEqual(["a-01"]);

        await wrapper.find('[data-testid="filter-all"]').trigger("click");
        await wrapper.find('input[type="search"]').setValue("c-0");
        expect(wrapper.findAll('[data-testid="item-no"]').map((cell) => cell.text())).toEqual(["c-01"]);
    });

    it("shows the total as the amazon and boss selections combined, and switches to a channel to confirm", async () => {
        vi.mocked(fetchCurrentItemSelection).mockImplementation(async (scope) =>
            scope === "total"
                ? {
                      data: null,
                      meta: {
                          scope: "total",
                          item_nos: ["a-01", "c-01"],
                          uses_candidates: true,
                          amazon_item_nos: ["a-01"],
                          boss_item_nos: ["a-01", "c-01"],
                          amazon_uses_candidates: true,
                          boss_uses_candidates: false,
                          fiscal_year_start: "2026-04-01",
                      },
                  }
                : currentWith(null, "amazon"),
        );
        vi.mocked(confirmItemSelection).mockResolvedValue({ ...selection(["a-01"], "2026-10-07T10:00:00+09:00"), scope: "amazon" });
        const wrapper = await mountView("/target-items");

        // 全体は確定せず、どちらのチャネルの対象品番かを並べる
        expect(fetchItemRanking).toHaveBeenLastCalledWith("total");
        expect(wrapper.find("form").exists()).toBe(false);
        expect(wrapper.find('[data-testid="total-count"]').text()).toBe("2品番");
        expect(wrapper.find('[data-testid="total-amazon"]').text()).toContain("未確定のため、Amazonの売上上位の品番を使っています");
        expect(wrapper.findAll('[data-testid="total-item-no"]').map((cell) => cell.text())).toEqual(["a-01", "c-01"]);
        expect(wrapper.findAll('[data-testid="total-row"]')[1]!.find('[data-testid="total-amazon"]').text()).toBe("—");
        await wrapper.find('[data-testid="total-filter-all"]').trigger("click");
        expect(wrapper.findAll('[data-testid="total-row"]')).toHaveLength(4);

        await wrapper.find('[data-testid="total-amazon"] button').trigger("click");
        await flushPromises();
        expect(wrapper.router.currentRoute.value.query).toEqual({ scope: "amazon" });
        expect(fetchCurrentItemSelection).toHaveBeenLastCalledWith("amazon");
        expect(wrapper.find('[data-testid="selection-warning"]').text()).toContain("確定するまでは、Amazonの売上上位2品番を対象品番として使います。");
        expect(wrapper.find('[data-testid="fact-current"]').text()).toContain("確定するまで、売上上位2品番を使っています");

        await wrapper.find("form").trigger("submit");
        await flushPromises();
        expect(confirmItemSelection).toHaveBeenCalledWith(["a-01", "b-01"], null, "amazon");
        expect(wrapper.text()).toContain("Amazonの対象品番を確定しました（1品番）。");
    });

    it("does not offer the history before anything is confirmed", async () => {
        vi.mocked(fetchCurrentItemSelection).mockResolvedValue(currentWith(null));

        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="open-history"]').exists()).toBe(false);
    });
});
