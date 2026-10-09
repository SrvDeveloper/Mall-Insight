import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import UnitPriceView from "@/views/UnitPriceView.vue";
import { fetchItemUnitPrices, saveItemUnitPrices } from "@/api/itemUnitPrices";
import { ApiError } from "@/api/client";
import { formatDateTime } from "@/components/salesTarget/format";
import type { ItemUnitPriceResponse, ItemUnitPriceRow } from "@/types/api";

vi.mock("@/api/itemUnitPrices", () => ({ fetchItemUnitPrices: vi.fn(), saveItemUnitPrices: vi.fn() }));

const registered: ItemUnitPriceRow = {
    item_no: "fisi-05",
    brand: "SHIORI",
    category: "老眼鏡",
    status: "active",
    status_label: "有効",
    is_target_item: true,
    registered: { unit_price: 4400, amazon_unit_price: null, boss_unit_price: null, source_label: "現行Excelから取り込み", changed_by: null, changed_at: "2026-10-08T09:00:00+09:00" },
    actual: { all: { quantity: 40, unit_price: 4150 }, amazon: { quantity: 30, unit_price: 3700 }, boss: { quantity: 10, unit_price: 5500 } },
};

const unregistered: ItemUnitPriceRow = {
    ...registered,
    item_no: "aa-01",
    is_target_item: false,
    registered: null,
    actual: { all: { quantity: 0, unit_price: null }, amazon: { quantity: 0, unit_price: null }, boss: { quantity: 0, unit_price: null } },
};

function response(): ItemUnitPriceResponse {
    return { data: [unregistered, registered], meta: { sales_from: "2025-10-09", sales_to: "2026-10-08" } };
}

async function mountView() {
    const wrapper = mount(UnitPriceView, { attachTo: document.body, global: { stubs: { RouterLink: true } } });
    await flushPromises();
    return wrapper;
}

describe("UnitPriceView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchItemUnitPrices).mockReset().mockResolvedValue(response());
        vi.mocked(saveItemUnitPrices).mockReset();
    });

    it("lists registered prices next to the average prices of each mall", async () => {
        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="summary-registered"]').text()).toContain("1 / 2品番");
        expect(wrapper.find('[data-testid="summary-period"]').text()).toContain("2025/10/09 〜 2026/10/08");
        const row = wrapper.findAll('[data-testid="price-row"]')[1]!;
        expect(row.find('[data-testid="registered-all"]').text()).toBe("4,400");
        expect(row.find('[data-testid="registered-amazon"]').text()).toBe("—");
        expect(row.find('[data-testid="actual-amazon"]').text()).toBe("平均 3,700（30個）");
        expect(row.find('[data-testid="actual-boss"]').text()).toBe("平均 5,500（10個）");
        expect(wrapper.findAll('[data-testid="price-row"]')[0]!.find('[data-testid="actual-all"]').text()).toBe("販売実績なし");
        wrapper.unmount();
    });

    it("shows only the last changed time and the user's name in the changed column", async () => {
        const changedOnScreen: ItemUnitPriceRow = { ...registered, item_no: "fisi-06", registered: { ...registered.registered!, source_label: "画面で変更", changed_by: "山田 太郎" } };
        vi.mocked(fetchItemUnitPrices).mockResolvedValue({ ...response(), data: [registered, changedOnScreen] });
        const wrapper = await mountView();
        const changed = wrapper.findAll('[data-testid="changed"]').map((cell) => cell.text());

        expect(changed[0]).toBe(formatDateTime("2026-10-08T09:00:00+09:00"));
        expect(changed[1]).toBe(`${formatDateTime("2026-10-08T09:00:00+09:00")} 山田 太郎`);
        wrapper.unmount();
    });

    it("filters to target items, registered items and by keyword", async () => {
        const wrapper = await mountView();
        const itemNos = () => wrapper.findAll('[data-testid="price-row"] .font-mono').map((cell) => cell.text());

        await wrapper.find('[data-testid="filter-target"]').trigger("click");
        expect(itemNos()).toEqual(["fisi-05"]);
        await wrapper.find('[data-testid="filter-unregistered"]').trigger("click");
        expect(itemNos()).toEqual(["aa-01"]);
        await wrapper.find('[data-testid="filter-all"]').trigger("click");
        await wrapper.find('input[type="search"]').setValue("fisi");
        expect(itemNos()).toEqual(["fisi-05"]);
        wrapper.unmount();
    });

    it("edits the three prices of an item in a dialog and leaves blank ones to the average", async () => {
        vi.mocked(saveItemUnitPrices).mockResolvedValue();
        const wrapper = await mountView();

        await wrapper.find('[data-testid="edit-price-fisi-05"]').trigger("click");
        const dialog = wrapper.find('[data-testid="price-dialog"]');
        expect(dialog.find("h2").text()).toBe("fisi-05 の単価を編集");
        expect(wrapper.findAll('[data-testid="price-row"] input')).toHaveLength(0);
        const allInput = dialog.find('input[aria-label="fisi-05 の全体の単価"]');
        expect((allInput.element as HTMLInputElement).value).toBe("4400");
        expect(document.activeElement).toBe(allInput.element);
        expect(dialog.find('input[aria-label="fisi-05 のAmazonの単価"]').attributes("placeholder")).toBe("3,700");
        expect(dialog.find('[data-testid="dialog-actual-boss"]').text()).toBe("平均 5,500（10個）");
        await dialog.find('input[aria-label="fisi-05 のAmazonの単価"]').setValue("3600");
        await dialog.find("form").trigger("submit");
        await flushPromises();

        expect(saveItemUnitPrices).toHaveBeenCalledWith("fisi-05", { unit_price: 4400, amazon_unit_price: 3600, boss_unit_price: null });
        expect(fetchItemUnitPrices).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="price-dialog"]').exists()).toBe(false);
        expect(wrapper.text()).toContain("fisi-05 の単価を保存しました。");
        wrapper.unmount();
    });

    it("keeps the dialog open with the error under the field when the input is invalid", async () => {
        vi.mocked(saveItemUnitPrices).mockRejectedValue(new ApiError("入力内容に誤りがあります。", 422, { boss_unit_price: ["BOSSの単価には、1以上の数値を指定してください。"] }));
        const wrapper = await mountView();

        await wrapper.find('[data-testid="edit-price-aa-01"]').trigger("click");
        expect(wrapper.find('[data-testid="price-dialog"] h2').text()).toBe("aa-01 の単価を登録");
        await wrapper.find('input[aria-label="aa-01 のBOSSの単価"]').setValue("0");
        await wrapper.find('[data-testid="price-dialog"] form').trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="price-dialog"]').text()).toContain("BOSSの単価には、1以上の数値を指定してください。");
        expect(fetchItemUnitPrices).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("closes the dialog without saving by cancel, the close button, Escape and the backdrop", async () => {
        const wrapper = await mountView();
        const open = () => wrapper.find('[data-testid="edit-price-fisi-05"]').trigger("click");
        const isOpen = () => wrapper.find('[data-testid="price-dialog"]').exists();

        await open();
        await wrapper
            .findAll('[data-testid="price-dialog"] button')
            .find((button) => button.text() === "キャンセル")!
            .trigger("click");
        expect(isOpen()).toBe(false);
        await open();
        await wrapper.find('[data-testid="price-dialog"] button[aria-label="閉じる"]').trigger("click");
        expect(isOpen()).toBe(false);
        await open();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(isOpen()).toBe(false);
        await open();
        await wrapper.find('[data-testid="price-dialog"]').element.parentElement!.dispatchEvent(new MouseEvent("click"));
        await flushPromises();
        expect(isOpen()).toBe(false);

        expect(saveItemUnitPrices).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
