import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SeasonalFactorView from "@/views/SeasonalFactorView.vue";
import { fetchSeasonalFactor, fetchSeasonalFactors, saveSeasonalFactor } from "@/api/seasonalFactors";
import type { SeasonalFactorDetailResponse, SeasonalFactorListResponse, SeasonalFactorRecord, SeasonalIndexSummary } from "@/types/api";

vi.mock("@/api/seasonalFactors", () => ({ fetchSeasonalFactors: vi.fn(), fetchSeasonalFactor: vi.fn(), saveSeasonalFactor: vi.fn() }));

const meta = { min_factor: 0.1, max_factor: 5, min_monthly_quantity: 20 };
const itemFactors = [0.51, 0.62, 1.03, 1.66, 0.11, 2.06, 2.52, 1.03, 0.97, 0.49, 0.6, 0.4];

function level(source: "item" | "category" | "overall", factors: number[] | null, monthlyQuantity = 67): SeasonalIndexSummary {
    return {
        source,
        source_label: source === "category" ? "調光サングラス" : source === "item" ? "ph5099" : "対象品番の全体",
        factors,
        years: Array(12).fill(2),
        monthly_quantity: monthlyQuantity,
        is_usable: factors !== null && monthlyQuantity >= 20,
    };
}

function record(factors: number[] | null): SeasonalFactorRecord {
    return { id: 1, factors, initial_factors: itemFactors, initial_source: "item", note: null, changed_by: "担当者", changed_at: "2026-10-13T12:00:00+09:00" };
}

function list(): SeasonalFactorListResponse {
    return {
        data: [
            { item_no: "ph5099", brand: "FEELLIFE", category: "調光サングラス", current: null, initial: level("item", itemFactors) },
            { item_no: "fisi-05", brand: "SHIORI", category: "老眼鏡", current: record(Array(12).fill(1)), initial: null },
        ],
        meta,
    };
}

function detail(current: SeasonalFactorRecord | null = null): SeasonalFactorDetailResponse {
    return {
        data: {
            item_no: "ph5099",
            brand: "FEELLIFE",
            category: "調光サングラス",
            is_target: true,
            current,
            initial: level("item", itemFactors),
            levels: [level("item", itemFactors), level("category", [1, 1, 1, 1, 1, 1.5, 1.5, 1, 1, 1, 1, 0.5]), level("overall", null, 1364)],
            sales: [
                { year: 2024, months: [6, 11, 40, 48, 1, 104, 90, 0, 0, 0, 0, 0] },
                { year: 2026, months: [86, 82, 103, 67, 218, 238, 109, 66, 69, 17, null, null] },
            ],
            history: current ? [current] : [],
        },
        meta,
    };
}

async function mountView(path = "/seasonal-factors") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: SeasonalFactorView }] });
    await router.push(path);
    const wrapper = mount(SeasonalFactorView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

const inputValues = (wrapper: Awaited<ReturnType<typeof mountView>>["wrapper"]): string[] => wrapper.findAll('[data-testid="factor-input"]').map((input) => (input.element as HTMLInputElement).value);

describe("SeasonalFactorView", () => {
    beforeEach(() => {
        vi.mocked(fetchSeasonalFactors).mockReset().mockResolvedValue(list());
        vi.mocked(fetchSeasonalFactor).mockReset().mockResolvedValue(detail());
        vi.mocked(saveSeasonalFactor).mockReset();
    });

    it("lists the target items with the registered factors and the ones found in past sales", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("季節係数");
        const rows = wrapper.findAll('[data-testid="factor-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain("未登録");
        expect(rows[0]!.text()).toContain("品番の実績");
        expect(rows[1]!.find('[data-testid="current-bars"]').exists()).toBe(true);

        await wrapper.find('[data-testid="filter-registered"]').trigger("click");
        expect(wrapper.findAll('[data-testid="factor-row"]')).toHaveLength(1);
        wrapper.unmount();
    });

    it("fills the factors found in past sales as initial values, marks possible stockouts and registers the edited factors", async () => {
        vi.mocked(saveSeasonalFactor).mockResolvedValue(detail(record([0.51, 0.62, 1.03, 1.66, 1.5, 2.06, 2.52, 1.03, 0.97, 0.49, 0.6, 0.4])));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="factor-row"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.item).toBe("ph5099");
        expect(inputValues(wrapper)).toEqual(itemFactors.map((factor) => factor.toFixed(2)));
        // 2024年5月（1個）と8〜12月（0個）は欠品していた可能性がある
        const low = wrapper.find('[data-testid="sales-table"]').findAll("tbody tr")[0]!.findAll("[data-low]");
        expect(low.map((cell) => cell.text())).toEqual(["1", "0", "0", "0", "0", "0"]);
        expect(wrapper.find('[data-testid="low-note"]').exists()).toBe(true);
        // 全体の係数は求められないため選べない
        expect(wrapper.find('[data-testid="fill-overall"]').attributes("disabled")).toBeDefined();

        // 欠品で小さく出た5月を直す。範囲外の値は保存できない
        const inputs = wrapper.findAll('[data-testid="factor-input"]');
        await inputs[4]!.setValue("0.05");
        expect(wrapper.find('[data-testid="factor-invalid"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="factor-save"]').attributes("disabled")).toBeDefined();
        await inputs[4]!.setValue("1.5");
        await wrapper.find('[data-testid="factor-note"]').setValue("2024年5月は欠品");
        await wrapper.find('[data-testid="factor-save"]').trigger("click");
        await flushPromises();

        expect(saveSeasonalFactor).toHaveBeenCalledWith("ph5099", [0.51, 0.62, 1.03, 1.66, 1.5, 2.06, 2.52, 1.03, 0.97, 0.49, 0.6, 0.4], "2024年5月は欠品");
        expect(wrapper.find('[data-testid="factor-saved"]').text()).toContain("季節係数を保存しました。");
        expect(fetchSeasonalFactors).toHaveBeenCalledTimes(2);

        // カテゴリーの係数・すべて1で入れ直せる
        await wrapper.find('[data-testid="fill-category"]').trigger("click");
        expect(inputValues(wrapper)[5]).toBe("1.50");
        await wrapper.find('[data-testid="fill-ones"]').trigger("click");
        expect(new Set(inputValues(wrapper))).toEqual(new Set(["1.00"]));
        expect(wrapper.find('[data-testid="factor-average"]').text()).toBe("平均 1.00");
        wrapper.unmount();
    });

    it("starts from the registered factors and stops the registration after confirming", async () => {
        vi.mocked(fetchSeasonalFactor).mockResolvedValue(detail(record(Array(12).fill(1.2))));
        vi.mocked(saveSeasonalFactor).mockResolvedValue(detail(record(null)));
        vi.spyOn(window, "confirm").mockReturnValue(true);
        const { wrapper } = await mountView("/seasonal-factors?item=ph5099");

        expect(new Set(inputValues(wrapper))).toEqual(new Set(["1.20"]));
        expect(wrapper.find('[data-testid="factor-history"]').text()).toContain("担当者");
        await wrapper.find('[data-testid="factor-stop"]').trigger("click");
        await flushPromises();

        expect(saveSeasonalFactor).toHaveBeenCalledWith("ph5099", null, null);
        wrapper.unmount();
    });
});
