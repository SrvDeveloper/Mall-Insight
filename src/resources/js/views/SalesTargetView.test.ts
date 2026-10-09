import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import SalesTargetView from "@/views/SalesTargetView.vue";
import {
    carryOverSalesTargets,
    createRatioCategory,
    fetchCarryOverCandidates,
    fetchSalesTargetHistory,
    fetchSalesTargets,
    renameRatioCategory,
    saveItemRatioCategory,
    saveMonthlySalesRatio,
    saveSalesTarget,
} from "@/api/salesTargets";
import type { ItemTargetPlan, SalesTarget, SalesTargetResponse } from "@/types/api";

vi.mock("@/api/salesTargets", () => ({
    fetchSalesTargets: vi.fn(),
    fetchCarryOverCandidates: vi.fn(),
    carryOverSalesTargets: vi.fn(),
    saveSalesTarget: vi.fn(),
    fetchSalesTargetHistory: vi.fn(),
    saveMonthlySalesRatio: vi.fn(),
    saveItemRatioCategory: vi.fn(),
    createRatioCategory: vi.fn(),
    renameRatioCategory: vi.fn(),
}));

const MONTHS = ["2026-04", "2026-05", "2026-06", "2026-07", "2026-08", "2026-09", "2026-10", "2026-11", "2026-12", "2027-01", "2027-02", "2027-03"];
/** 老眼の比率。4月10%・5月20%、残りの10か月は7% */
const READING = [1000, 2000, 700, 700, 700, 700, 700, 700, 700, 700, 700, 700];

function target(overrides: Partial<SalesTarget> = {}): SalesTarget {
    return {
        id: 1,
        fiscal_year: 2026,
        item_no: "fl-01",
        annual_quantity: 1200,
        monthly_quantities: Array(12).fill(null),
        sku_quantities: {},
        note: null,
        source: "excel",
        source_label: "現行Excelから取り込み",
        changed_by: null,
        changed_at: "2026-10-08T09:00:00+09:00",
        ...overrides,
    };
}

const calculated: ItemTargetPlan = {
    item_no: "fl-01",
    brand: "FEELLIFE",
    category: "老眼鏡",
    exists_in_crosswalker: true,
    is_target_item: true,
    ratio_category_id: 1,
    ratio_category_name: "老眼",
    is_ratio_category_set: true,
    status: "calculated",
    status_label: "割り振り済み",
    target: target({ monthly_quantities: [150, ...Array(11).fill(null)], sku_quantities: { "10": 1000 } }),
    monthly: [150, 240, 84, 84, 84, 84, 84, 84, 84, 84, 84, 84],
    month_overridden: [true, ...Array(11).fill(false)],
    sku_total: 1300,
    unit_price: 4400,
    actual_unit_price: 3950,
    annual_amount: 5720000,
    monthly_amounts: [715000, 1144000, 400400, 400400, 400400, 400400, 400400, 400400, 400400, 400400, 400400, 400400],
    has_unpriced_sku: false,
    skus: [
        {
            sku_id: 10,
            sku_code: "fl-01-1-10",
            sales_quantity: 30,
            default_annual: 900,
            override_annual: 1000,
            annual: 1000,
            monthly: [125, 200, 70, 70, 70, 70, 70, 70, 70, 70, 70, 70],
            unit_price: 4400,
            price_basis: "registered",
            price_basis_label: "登録した単価",
            annual_amount: 4400000,
            monthly_amounts: [550000, 880000, 308000, 308000, 308000, 308000, 308000, 308000, 308000, 308000, 308000, 308000],
        },
        {
            sku_id: 11,
            sku_code: "fl-01-1-15",
            sales_quantity: 10,
            default_annual: 300,
            override_annual: null,
            annual: 300,
            monthly: [37.5, 60, 21, 21, 21, 21, 21, 21, 21, 21, 21, 21],
            unit_price: 4400,
            price_basis: "registered",
            price_basis_label: "登録した単価",
            annual_amount: 1320000,
            monthly_amounts: [165000, 264000, 92400, 92400, 92400, 92400, 92400, 92400, 92400, 92400, 92400, 92400],
        },
    ],
};

const noAmount = {
    default_annual: null,
    override_annual: null,
    annual: null,
    monthly: null,
    unit_price: null,
    price_basis: null,
    price_basis_label: null,
    annual_amount: null,
    monthly_amounts: null,
};

const unregistered: ItemTargetPlan = {
    ...calculated,
    item_no: "fl-02",
    ratio_category_id: null,
    ratio_category_name: null,
    is_ratio_category_set: false,
    category: "インスタントグラス",
    status: "no_target",
    status_label: "年間販売目標が登録されていません",
    target: null,
    monthly: null,
    month_overridden: Array(12).fill(false),
    sku_total: null,
    unit_price: null,
    actual_unit_price: 3000,
    annual_amount: null,
    monthly_amounts: null,
    has_unpriced_sku: false,
    skus: [
        { ...noAmount, sku_id: 20, sku_code: "fl-02-1-10", sales_quantity: 30 },
        { ...noAmount, sku_id: 21, sku_code: "fl-02-1-15", sales_quantity: 10 },
    ],
};

function response(data: ItemTargetPlan[] = [calculated, unregistered], fiscalYear = 2026): SalesTargetResponse {
    return {
        data,
        meta: {
            fiscal_year: fiscalYear,
            current_fiscal_year: 2026,
            months: MONTHS,
            ratio_categories: [
                {
                    id: 1,
                    code: "reading",
                    name: "老眼",
                    ratio: { ratio_category_id: 1, ratios: READING, source: "excel", source_label: "現行Excelから取り込み", changed_by: null, changed_at: "2026-10-08T09:00:00+09:00" },
                },
                { id: 2, code: "pc_sunglasses", name: "PCサングラス", ratio: null },
                { id: 3, code: "photochromic_reading", name: "調光老眼", ratio: null },
                { id: 4, code: "ready_made_myopia", name: "既成近眼", ratio: null },
            ],
            composition_from: "2025-10-09",
            composition_to: "2026-10-08",
        },
    };
}

async function mountView(path = "/sales-targets") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/sales-targets", component: SalesTargetView }] });
    await router.push(path);
    const wrapper = mount(SalesTargetView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

function button(wrapper: Awaited<ReturnType<typeof mountView>>["wrapper"], row: number, testid: string) {
    return wrapper.findAll('[data-testid="target-row"]')[row]!.find(`[data-testid="${testid}"]`);
}

describe("SalesTargetView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchSalesTargets).mockReset().mockResolvedValue(response());
        vi.mocked(saveSalesTarget).mockReset();
        vi.mocked(fetchSalesTargetHistory).mockReset();
        vi.mocked(saveMonthlySalesRatio).mockReset();
        vi.mocked(saveItemRatioCategory).mockReset();
        vi.mocked(fetchCarryOverCandidates).mockReset();
        vi.mocked(carryOverSalesTargets).mockReset();
        vi.mocked(createRatioCategory).mockReset();
        vi.mocked(renameRatioCategory).mockReset();
    });

    it("adds a ratio category and renames one", async () => {
        const { wrapper } = await mountView("/sales-targets?tab=ratios");

        await wrapper.find('[data-testid="add-category"]').trigger("click");
        await wrapper.find('[data-testid="new-category-name"]').setValue("ブルーライトカット");
        await wrapper.find('[data-testid="add-category-save"]').trigger("click");
        await flushPromises();
        expect(createRatioCategory).toHaveBeenCalledWith("ブルーライトカット");
        expect(wrapper.text()).toContain("区分「ブルーライトカット」を足しました。12か月の比率を入れて保存してください。");

        await wrapper.find('[data-testid="rename-1"]').trigger("click");
        await wrapper.find('[data-testid="rename-input"]').setValue("リーディンググラス");
        await wrapper.find('[data-testid="rename-save"]').trigger("click");
        await flushPromises();
        expect(renameRatioCategory).toHaveBeenCalledWith(1, "リーディンググラス");
        expect(wrapper.text()).toContain("区分「老眼」の名前を「リーディンググラス」に変えました。");
        expect(fetchSalesTargets).toHaveBeenCalledTimes(3);
        wrapper.unmount();
    });

    it("carries over the chosen items and contents from the previous year", async () => {
        vi.mocked(fetchSalesTargets).mockResolvedValue(response([], 2027));
        vi.mocked(fetchCarryOverCandidates).mockResolvedValue([
            { item_no: "fl-01", annual_quantity: 1200, monthly_override_count: 1, sku_override_count: 3, current_annual_quantity: null },
            { item_no: "fl-02", annual_quantity: 800, monthly_override_count: 0, sku_override_count: 0, current_annual_quantity: 900 },
        ]);
        vi.mocked(carryOverSalesTargets).mockResolvedValue(2);
        const { wrapper } = await mountView("/sales-targets?fy=2027");

        await wrapper.find('[data-testid="open-carry-over"]').trigger("click");
        await flushPromises();
        const dialog = wrapper.find('[role="dialog"]');
        expect(dialog.text()).toContain("2026年度から2027年度へ目標を引き継ぐ");
        // この年度に目標のある品番は、はじめは選ばない
        const checked = () => dialog.findAll('[data-testid="carry-over-row"] input').map((input) => (input.element as HTMLInputElement).checked);
        expect(checked()).toEqual([true, false]);
        expect(dialog.find('[data-testid="carry-over-submit"]').text()).toBe("1品番を引き継ぐ");

        await dialog.findAll('[data-testid="carry-over-row"]')[1]!.trigger("click");
        expect(dialog.text()).toContain("1品番は、2027年度の今の目標が置き換わります。");
        await dialog.find('[data-testid="include-monthly"]').setValue(true);
        await dialog.find('[data-testid="include-skus"]').setValue(false);
        await dialog.find("form").trigger("submit");
        await flushPromises();

        expect(fetchCarryOverCandidates).toHaveBeenCalledWith(2027);
        expect(carryOverSalesTargets).toHaveBeenCalledWith(2027, ["fl-01", "fl-02"], true, false);
        expect(wrapper.text()).toContain("2026年度から2品番の目標を引き継ぎました。");
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("lists each item's target split into months with the reason when it cannot be split", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find('[data-testid="fiscal-year"]').text()).toContain("2026年度");
        expect(wrapper.find('[data-testid="summary-registered"]').text()).toContain("1 / 2品番");
        expect(wrapper.find('[data-testid="summary-category"]').text()).toContain("未設定 1品番");
        const rows = wrapper.findAll('[data-testid="target-row"]');
        const cells = rows[0]!.findAll('[data-testid="month-cell"]').map((cell) => cell.text());
        expect(cells.slice(0, 3)).toEqual(["150", "240", "84"]);
        expect(rows[0]!.find('[data-testid="month-cell"]').attributes("title")).toBe("手で直した月");
        expect(rows[0]!.find('[data-testid="sku-total"]').text()).toBe("SKU計 1,300");
        expect(rows[1]!.find('[data-testid="status"]').text()).toBe("年間販売目標が登録されていません");
        expect(rows[1]!.find('[data-testid="edit-target"]').text()).toBe("登録");
    });

    it("switches the list to approximate sales amounts in thousand yen", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="measure-amount"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ measure: "amount" });
        const row = wrapper.findAll('[data-testid="target-row"]')[0]!;
        expect(row.find('[data-testid="annual-amount"]').text()).toBe("5,720");
        expect(row.find('[data-testid="unit-price"]').text()).toBe("登録単価 4,400円");
        expect(
            row
                .findAll('[data-testid="month-cell"]')
                .map((cell) => cell.text())
                .slice(0, 2),
        ).toEqual(["715", "1,144"]);
        expect(wrapper.find('[data-testid="summary-total"]').text()).toContain("売上金額の概算 572万円（税込）");
        wrapper.unmount();
    });

    it("opens an item to show how its target is shared among skus", async () => {
        const { wrapper } = await mountView();

        await wrapper.find('[aria-label="fl-01 のSKUを表示"]').trigger("click");

        const skuRows = wrapper.findAll('[data-testid="sku-row"]');
        expect(skuRows.map((row) => row.find("td").text())).toEqual(["fl-01-1-10", "fl-01-1-15"]);
        expect(skuRows[0]!.text()).toContain("上書き");
        expect(skuRows[1]!.text()).toContain("構成比");
        wrapper.unmount();
    });

    it("registers a target with months and skus left blank to use the ratio and the composition", async () => {
        vi.mocked(saveSalesTarget).mockResolvedValue(target({ item_no: "fl-02" }));
        const { wrapper } = await mountView();

        await button(wrapper, 1, "edit-target").trigger("click");
        const dialog = wrapper.find('[role="dialog"]');
        expect(dialog.text()).toContain("この品番の区分の月別販売比率が無いため、月に分けられません");
        await dialog.find('[data-testid="annual"]').setValue("400");
        // 構成比は販売数 30:10
        expect(dialog.findAll("tbody tr")[0]!.text()).toContain("300");
        await dialog.find('input[aria-label="fl-02-1-15 の年間の数"]').setValue("200");
        // 単価が未登録なら平均単価（3,000円）で見積もる。単価は単価の画面で変える
        expect(dialog.find('[data-testid="estimated-amount"]').text()).toBe("150万円");
        expect(dialog.find('[data-testid="price-note"]').text()).toContain("平均 3,000円（単価は未登録）");
        expect(dialog.find('[data-testid="price-note"] a').attributes("href")).toBe("/unit-prices");
        expect(dialog.find('[data-testid="sku-total"]').text()).toBe("SKUの合計 500（年間より 100 多い。SKUの数を正とします）");
        await dialog.find("form").trigger("submit");
        await flushPromises();

        expect(saveSalesTarget).toHaveBeenCalledWith({
            fiscal_year: 2026,
            item_no: "fl-02",
            annual_quantity: 400,
            monthly_quantities: Array(12).fill(null),
            sku_quantities: { 20: null, 21: 200 },
            note: null,
        });
        expect(fetchSalesTargets).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain("fl-02 の2026年度の販売目標を保存しました。");
        wrapper.unmount();
    });

    it("shows the default of each month from the ratio and warns when the months do not add up to the annual target", async () => {
        const { wrapper } = await mountView();

        await button(wrapper, 0, "edit-target").trigger("click");
        const dialog = wrapper.find('[role="dialog"]');
        const may = dialog.find('input[aria-label="5月の目標"]');
        expect(may.attributes("placeholder")).toBe("240");
        expect((dialog.find('input[aria-label="4月の目標"]').element as HTMLInputElement).value).toBe("150");
        expect(dialog.find('[data-testid="monthly-total"]').text()).toBe("12か月の合計 1,230（年間と 30 違います）");

        await dialog
            .findAll("button")
            .find((element) => element.text() === "手直しを消す")!
            .trigger("click");
        expect(dialog.find('[data-testid="monthly-total"]').text()).toBe("12か月の合計 1,200");
        wrapper.unmount();
    });

    it("shows the history of an item's target", async () => {
        vi.mocked(fetchSalesTargetHistory).mockResolvedValue([
            target({ id: 2, annual_quantity: 1500, changed_by: "目標 担当", source: "screen", source_label: "画面", note: "上方修正", sku_quantities: { "10": 1000 } }),
            target(),
        ]);
        const { wrapper } = await mountView();

        await button(wrapper, 0, "show-history").trigger("click");
        await flushPromises();

        expect(fetchSalesTargetHistory).toHaveBeenCalledWith(2026, "fl-01");
        const rows = wrapper.findAll('[data-testid="target-history"] tbody tr');
        expect(rows[0]!.text()).toContain("今の目標");
        expect(rows[0]!.text()).toContain("1,500");
        expect(rows[0]!.text()).toContain("月 0か月・SKU 1件");
        expect(rows[1]!.text()).toContain("現行Excelから取り込み");
        wrapper.unmount();
    });

    it("changes the ratio category of an item", async () => {
        const { wrapper } = await mountView();

        await wrapper.findAll('[data-testid="category-select"]')[1]!.setValue("1");
        await flushPromises();

        expect(saveItemRatioCategory).toHaveBeenCalledWith("fl-02", 1);
        expect(fetchSalesTargets).toHaveBeenCalledTimes(2);
    });

    it("moves to another fiscal year and keeps it in the url", async () => {
        const { wrapper, router } = await mountView();
        vi.mocked(fetchSalesTargets).mockResolvedValue(response([], 2027));

        await wrapper.find('[data-testid="next-year"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ fy: "2027" });
        expect(fetchSalesTargets).toHaveBeenLastCalledWith(2027);
        expect(wrapper.find('[data-testid="fiscal-year"]').text()).toContain("2027年度");
    });

    it("saves the monthly ratios of a category only when they total one hundred percent", async () => {
        vi.mocked(saveMonthlySalesRatio).mockResolvedValue(response().meta.ratio_categories[0]!.ratio!);
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="tab-ratios"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ tab: "ratios" });

        const row = wrapper.find('[data-testid="ratio-row-1"]');
        // 更新日時だけを出す（誰が・どこから変えたかは出さない）
        expect(wrapper.find('[data-testid="ratio-updated-1"]').text()).toBe("2026/10/08 09:00");
        const save = () => wrapper.find('[data-testid="ratio-save-1"]');
        expect(save().attributes("disabled")).toBeDefined();
        await row.find('input[aria-label="老眼の4月の比率（%）"]').setValue("9.5");
        expect(wrapper.find('[data-testid="ratio-total-1"]').text()).toBe("99.50%");
        expect(save().attributes("disabled")).toBeDefined();
        await row.find('input[aria-label="老眼の5月の比率（%）"]').setValue("20.5");
        expect(wrapper.find('[data-testid="ratio-total-1"]').text()).toBe("100.00%");

        await save().trigger("click");
        await flushPromises();

        expect(saveMonthlySalesRatio).toHaveBeenCalledWith(1, [950, 2050, 700, 700, 700, 700, 700, 700, 700, 700, 700, 700]);
        expect(wrapper.text()).toContain("老眼の月別販売比率を保存しました。");
    });
});
