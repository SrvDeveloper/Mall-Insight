import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SkuSalesView from "@/views/SkuSalesView.vue";
import { fetchSalesActuals } from "@/api/actuals";
import { ApiError } from "@/api/client";
import { salesResponse } from "@/views/salesActuals.fixture";

vi.mock("@/api/actuals", () => ({ fetchSalesActuals: vi.fn() }));

/** 1品番だけの応答（fl-01 は度数のある品番、si-13 は度数の無い品番）。 */
function itemResponse(index = 0) {
    const response = salesResponse();
    return { ...response, data: response.data.slice(index, index + 1) };
}

async function mountView(path = "/item-sales/fl-01") {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/item-sales", name: "item-sales", component: { template: "<div />" } },
            { path: "/item-sales/:itemNo", name: "sku-sales", component: SkuSalesView },
        ],
    });
    await router.push(path);
    const wrapper = mount(SkuSalesView, { attachTo: document.body, global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("SkuSalesView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesActuals).mockReset().mockResolvedValue(itemResponse());
    });

    it("sums the item up with its mall share and lists the SKUs by the period total with two-colored bars", async () => {
        const { wrapper } = await mountView();

        expect(fetchSalesActuals).toHaveBeenCalledWith("total", "month", "fl-01", null);
        expect(wrapper.find('[data-testid="item-sum"]').text()).toBe("9");
        // BOSS 2＋5＋1 ＝ 8、Amazon 1
        expect(wrapper.find('[data-testid="item-malls"]').text()).toBe("BOSS 8（88.9%）Amazon 1（11.1%）");
        expect(wrapper.find('[data-testid="top-sku"]').text()).toBe("fl-01-1-10");
        expect(wrapper.find('[data-testid="best-period"]').text()).toBe("26/10");
        const rows = wrapper.findAll("button[aria-pressed]").filter((row) => row.attributes("data-testid")?.startsWith("sku-"));
        expect(rows.map((row) => row.attributes("data-testid"))).toEqual(["sku-1", "sku-2"]);
        // 一番売れた fl-01-1-10（BOSS 7・Amazon 1）を1とした棒
        const bar = rows[0]!.find('[data-testid="mall-bar"]');
        expect(bar.attributes("data-boss")).toBe("0.875");
        expect(bar.attributes("data-amazon")).toBe("0.125");
        wrapper.unmount();
    });

    it("shows the top SKU by default and another SKU when picked, keeping it in the URL", async () => {
        const { wrapper, router } = await mountView();

        expect(wrapper.find('[data-testid="detail-code"]').text()).toBe("fl-01-1-10");
        expect(
            wrapper
                .find('[data-testid="sku-detail"]')
                .findAll('[data-testid="chart-line"]')
                .map((line) => line.attributes("data-series")),
        ).toEqual(["boss", "amazon"]);
        // 月ごとの表は新しい月を上にする
        const lines = wrapper.find('[data-testid="detail-table"]').findAll("tbody tr");
        expect(lines[0]!.text()).toContain("26/10");
        expect(lines[1]!.findAll("td").map((cell) => cell.text())).toEqual(["2", "1", "3", ""]);
        // ツールチップの前月比・前年比は、そのSKUの値で比べる（10月は BOSS 5・Amazon 0、前月は BOSS 1・Amazon 1、前年は BOSS 4）
        await wrapper.find('[data-testid="sku-detail"]').findAll('[data-testid="chart-band"]')[2]!.trigger("mouseenter");
        const tooltip = wrapper.find('[data-testid="chart-tooltip"]');
        expect(tooltip.find('[data-testid="tooltip-previous-amazon"]').text()).toBe("0%");
        expect(tooltip.find('[data-testid="tooltip-total-previous"]').text()).toBe("250%");
        expect(tooltip.find('[data-testid="tooltip-last-year-boss"]').text()).toBe("125%");

        await wrapper.find('[data-testid="sku-2"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.query).toEqual({ sku: "2" });
        expect(wrapper.find('[data-testid="detail-code"]').text()).toBe("fl-01-1-15");
        wrapper.unmount();
    });

    it("opens the color and size table in a dialog and moves to the SKU picked there", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="open-matrix"]').trigger("click");
        const dialog = wrapper.find('[data-testid="matrix-dialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.text()).toContain("+1.0");
        expect(dialog.text()).toContain("+1.5");
        expect(dialog.find('[data-testid="size-sum-10"]').text()).toContain("8");

        await dialog.find('[data-testid="cell-1-15"]').trigger("click");
        await flushPromises();

        expect(wrapper.find('[data-testid="matrix-dialog"]').exists()).toBe(false);
        expect(router.currentRoute.value.query).toEqual({ sku: "2" });

        await wrapper.find('[data-testid="open-matrix"]').trigger("click");
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[data-testid="matrix-dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("greys out the color and size button for an item without sizes", async () => {
        vi.mocked(fetchSalesActuals).mockResolvedValue(itemResponse(1));
        const { wrapper } = await mountView("/item-sales/si-13");

        const button = wrapper.find('[data-testid="open-matrix"]');
        expect(button.attributes("disabled")).toBeDefined();
        expect(button.attributes("title")).toBe("この品番には度数がありません");
        wrapper.unmount();
    });

    it("asks for the period chosen and keeps the result with the reason when the period is not accepted", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="period-m3"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ from: "2026-07", to: "2026-10" });
        expect(fetchSalesActuals).toHaveBeenLastCalledWith("total", "month", "fl-01", { from: "2026-07", to: "2026-10" });

        vi.mocked(fetchSalesActuals).mockRejectedValue(new ApiError("入力内容に誤りがあります。", 422, { from: ["開始は終了と同じか、それより前にしてください。"] }));
        const from = wrapper.find('[data-testid="period-from"]');
        (from.element as HTMLInputElement).value = "2026-12";
        await from.trigger("change");
        await flushPromises();

        expect(wrapper.find('[data-testid="period-error"]').text()).toBe("開始は終了と同じか、それより前にしてください。");
        expect(wrapper.find('[data-testid="item-sum"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it("goes back to the item sales keeping the choices except the SKU", async () => {
        const { wrapper } = await mountView("/item-sales/fl-01?unit=day&sku=2");

        expect(fetchSalesActuals).toHaveBeenCalledWith("total", "day", "fl-01", null);
        expect(wrapper.find('[data-testid="back-to-items"]').attributes("href")).toBe("/item-sales?unit=day");
        // 日ごとは前日と比べる
        await wrapper.findAll('[data-testid="chart-band"]')[1]!.trigger("mouseenter");
        expect(wrapper.find('[data-testid="chart-tooltip"]').text()).toContain("前日比");
        wrapper.unmount();
    });

    it("tells that an item outside the target items cannot be shown", async () => {
        vi.mocked(fetchSalesActuals).mockResolvedValue({ ...itemResponse(), data: [] });
        const { wrapper } = await mountView("/item-sales/aa-01");

        expect(wrapper.find('[data-testid="not-target"]').text()).toContain("aa-01 は対象品番にありません。");
        wrapper.unmount();
    });
});
