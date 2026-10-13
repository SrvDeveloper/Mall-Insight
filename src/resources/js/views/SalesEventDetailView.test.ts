import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises } from "@vue/test-utils";
import {
    createSalesEventPeriod,
    deleteSalesEvent,
    deleteSalesEventPeriod,
    fetchSalesEvent,
    fetchSalesEventPeriod,
    fetchSalesEvents,
    updateSalesEvent,
    updateSalesEventPeriod,
} from "@/api/salesEvents";
import { ApiError } from "@/api/client";
import { list, meta, mountAt, periodDetail, sale, salePeriods, withPeriods } from "@/views/salesEvent.fixture";
import type { SalesEventPeriod, SalesEventResponse } from "@/types/api";

vi.mock("@/api/salesEvents", () => ({
    fetchSalesEvents: vi.fn(),
    fetchSalesEvent: vi.fn(),
    createSalesEvent: vi.fn(),
    updateSalesEvent: vi.fn(),
    deleteSalesEvent: vi.fn(),
    createSalesEventPeriod: vi.fn(),
    updateSalesEventPeriod: vi.fn(),
    deleteSalesEventPeriod: vi.fn(),
    fetchSalesEventPeriod: vi.fn(),
    saveSalesEventPeriodMultipliers: vi.fn(),
}));

const saleResponse = (periods = salePeriods, overrides = {}): SalesEventResponse => ({ data: withPeriods(periods, overrides), meta });
const added = { ...salePeriods[2]!, id: 14, starts_on: "2027-03-04", ends_on: "2027-03-11", days: 8 };

async function fillAddForm(wrapper: Awaited<ReturnType<typeof mountAt>>["wrapper"]): Promise<void> {
    await wrapper.find('[data-testid="open-period-add"]').trigger("click");
    await wrapper.find('[data-testid="add-starts-on"]').setValue("2027-03-04");
    await wrapper.find('[data-testid="add-ends-on"]').setValue("2027-03-11");
}

describe("SalesEventDetailView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesEvent).mockReset().mockResolvedValue(saleResponse());
        vi.mocked(fetchSalesEvents).mockReset().mockResolvedValue(list());
        vi.mocked(fetchSalesEventPeriod).mockReset().mockResolvedValue(periodDetail());
        for (const mock of [updateSalesEvent, deleteSalesEvent, createSalesEventPeriod, updateSalesEventPeriod, deleteSalesEventPeriod]) {
            vi.mocked(mock).mockReset();
        }
    });

    it("shows the event and its periods, upcoming first and ended from the newest", async () => {
        const { wrapper, router } = await mountAt("/sales-events/1?mall=rakuten");

        expect(fetchSalesEvent).toHaveBeenCalledWith(1);
        expect(wrapper.find("h1").text()).toContain("楽天スーパーセール");
        expect(wrapper.find('[data-testid="event-malls"]').text()).toBe("楽天市場");
        expect(wrapper.find('[data-testid="event-note"]').text()).toBe("4日20時から");
        // 期間の倍率は、この画面には出さない
        expect(wrapper.find('[data-testid="event-detail"]').exists()).toBe(false);

        const upcoming = wrapper.find('[data-testid="period-group-upcoming"]');
        expect(upcoming.findAll('[data-testid="period-link"]').map((link) => link.text())).toEqual(["2026/12/4〜12/7"]);
        expect(upcoming.find('[data-testid="period-multiplier"]').text()).toContain("4.67倍");
        expect(upcoming.find('[data-testid="period-multiplier"]').text()).toContain("過去2回から");
        const ended = wrapper.find('[data-testid="period-group-ended"]');
        expect(ended.findAll('[data-testid="period-link"]').map((link) => link.text())).toEqual(["2026/9/4〜9/7", "2026/6/4〜6/7"]);

        await wrapper.find('[data-testid="back-to-events"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.name).toBe("sales-events");
        expect(router.currentRoute.value.query).toEqual({ mall: "rakuten" });
        wrapper.unmount();
    });

    it("opens the period page with the mall when a period is pressed", async () => {
        const { wrapper, router } = await mountAt("/sales-events/1?mall=rakuten");

        await wrapper.find('[data-testid="period-group-upcoming"] [data-testid="period-row"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.name).toBe("sales-event-period");
        expect(router.currentRoute.value.params).toEqual({ eventId: "1", periodId: "13" });
        expect(router.currentRoute.value.query).toEqual({ mall: "rakuten" });
        expect(fetchSalesEventPeriod).toHaveBeenCalledWith(13);
        wrapper.unmount();
    });

    it("shows that the event was not found", async () => {
        vi.mocked(fetchSalesEvent).mockRejectedValue(new ApiError("見つかりません。", 404));
        const { wrapper } = await mountAt("/sales-events/9");

        expect(wrapper.find('[data-testid="not-found"]').text()).toContain("このイベントは見つかりませんでした。");
        wrapper.unmount();
    });

    it("adds a period and edits one in place", async () => {
        vi.mocked(createSalesEventPeriod).mockResolvedValue(added);
        vi.mocked(fetchSalesEvent)
            .mockResolvedValueOnce(saleResponse())
            .mockResolvedValueOnce(saleResponse([...salePeriods, added]));
        vi.mocked(updateSalesEventPeriod).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { starts_on: ["このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。"] }));
        const { wrapper } = await mountAt("/sales-events/1");

        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        await fillAddForm(wrapper);
        expect(wrapper.find('[role="dialog"]').attributes("aria-modal")).toBe("true");
        expect(wrapper.find('[data-testid="add-days"]').text()).toBe("8日間");
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();
        expect(createSalesEventPeriod).toHaveBeenCalledWith(1, { starts_on: "2027-03-04", ends_on: "2027-03-11", note: null });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセールに 2027/3/4〜3/11 の期間を追加しました。");
        expect(fetchSalesEvent).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="period-row"]')).toHaveLength(4);

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        await wrapper.find('[data-testid="period-add-close"]').trigger("click");
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);

        await wrapper.find('[data-testid="period-group-upcoming"] [data-testid="period-edit"]').trigger("click");
        await wrapper.find('[data-testid="edit-starts-on"]').setValue("2026-09-05");
        await wrapper.find('[data-testid="edit-save"]').trigger("click");
        await flushPromises();
        expect(updateSalesEventPeriod).toHaveBeenCalledWith(13, { starts_on: "2026-09-05", ends_on: "2026-12-07", note: null });
        expect(wrapper.find('[data-testid="edit-error"]').text()).toBe("このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。");
        wrapper.unmount();
    });

    it("keeps the add dialog open and shows a spinner until the new period appears", async () => {
        let finishCreate!: (period: SalesEventPeriod) => void;
        let finishRefresh!: (result: SalesEventResponse) => void;
        vi.mocked(createSalesEventPeriod).mockReturnValue(new Promise((resolve) => (finishCreate = resolve)));
        vi.mocked(fetchSalesEvent)
            .mockResolvedValueOnce(saleResponse())
            .mockReturnValueOnce(new Promise((resolve) => (finishRefresh = resolve)));
        const { wrapper } = await mountAt("/sales-events/1");

        await fillAddForm(wrapper);
        await wrapper.find('[data-testid="period-add"]').trigger("submit");

        expect(wrapper.find('[data-testid="period-add"]').attributes("aria-busy")).toBe("true");
        expect(wrapper.find('[data-testid="add-submit"] svg').classes()).toContain("animate-spin");
        expect(wrapper.find('[data-testid="add-submit"]').text()).toBe("追加中…");
        expect(wrapper.find('[data-testid="period-add-close"]').attributes("disabled")).toBeDefined();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(true);

        finishCreate(added);
        await flushPromises();
        expect(fetchSalesEvent).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(true);

        finishRefresh(saleResponse([...salePeriods, added]));
        await flushPromises();
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="period-row"]')).toHaveLength(4);
        wrapper.unmount();
    });

    it("retries the refresh without creating the period again", async () => {
        vi.mocked(createSalesEventPeriod).mockResolvedValue(added);
        vi.mocked(fetchSalesEvent)
            .mockResolvedValueOnce(saleResponse())
            .mockResolvedValueOnce(saleResponse())
            .mockResolvedValueOnce(saleResponse([...salePeriods, added]));
        const { wrapper } = await mountAt("/sales-events/1");

        await fillAddForm(wrapper);
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="add-error"]').text()).toContain("一覧を更新できませんでした");
        expect(wrapper.find('[data-testid="add-submit"]').text()).toBe("一覧を再読み込み");
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();

        expect(createSalesEventPeriod).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="period-row"]')).toHaveLength(4);
        wrapper.unmount();
    });

    it("keeps the period dialog open for validation errors and closes it with Escape", async () => {
        vi.mocked(createSalesEventPeriod).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { starts_on: ["別の期間と重なっています。"] }));
        const { wrapper } = await mountAt("/sales-events/1");

        await fillAddForm(wrapper);
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="add-error"]').text()).toBe("別の期間と重なっています。");
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("shows why a name cannot be used, and the edited event before closing its dialog", async () => {
        vi.mocked(updateSalesEvent)
            .mockRejectedValueOnce(new ApiError("入力内容を確認してください。", 422, { name: ["同じ名前のイベント（お買い物マラソン）が登録されています。期間はそのイベントに追加してください。"] }))
            .mockResolvedValueOnce(saleResponse(salePeriods, { name: "楽天スーパーセール改" }));
        vi.mocked(fetchSalesEvent)
            .mockResolvedValueOnce(saleResponse())
            .mockResolvedValueOnce(saleResponse(salePeriods, { name: "楽天スーパーセール改" }));
        const { wrapper } = await mountAt("/sales-events/1");

        await wrapper.find('[data-testid="edit-event"]').trigger("click");
        expect(wrapper.find('[data-testid="event-starts-on"]').exists()).toBe(false);
        await wrapper.find('[data-testid="event-name"]').setValue("お買い物マラソン");
        await wrapper.find("form").trigger("submit");
        await flushPromises();
        expect(updateSalesEvent).toHaveBeenCalledWith(1, { name: "お買い物マラソン", malls: ["rakuten"], note: "4日20時から", period: null });
        expect(wrapper.find('[data-testid="name-error"]').text()).toContain("同じ名前のイベント（お買い物マラソン）");

        await wrapper.find('[data-testid="event-name"]').setValue("楽天スーパーセール改");
        await wrapper.find("form").trigger("submit");
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.find("h1").text()).toContain("楽天スーパーセール改");
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセール改を保存しました。");
        wrapper.unmount();
    });

    it("deletes a period, and the event after confirming, then goes back to the list", async () => {
        vi.spyOn(window, "confirm").mockReturnValue(true);
        vi.mocked(deleteSalesEventPeriod).mockResolvedValue();
        vi.mocked(deleteSalesEvent).mockResolvedValue();
        const { wrapper, router } = await mountAt("/sales-events/1");

        await wrapper.find('[data-testid="period-group-ended"] [data-testid="period-delete"]').trigger("click");
        await flushPromises();
        expect(deleteSalesEventPeriod).toHaveBeenCalledWith(12);
        expect(wrapper.find('[data-testid="message"]').text()).toContain("2026/9/4〜9/7 の期間を削除しました。");

        await wrapper.find('[data-testid="delete-event"]').trigger("click");
        await flushPromises();
        expect(deleteSalesEvent).toHaveBeenCalledWith(1);
        expect(router.currentRoute.value.name).toBe("sales-events");
        // URL にモールが無ければ、イベントの最初のモールのタブへ戻る
        expect(router.currentRoute.value.query).toEqual({ mall: sale.malls[0]!.value });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセールを削除しました。");
        wrapper.unmount();
    });
});
