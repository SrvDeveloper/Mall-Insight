import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises } from "@vue/test-utils";
import { createSalesEvent, fetchSalesEvent, fetchSalesEvents } from "@/api/salesEvents";
import { list, meta, mountAt, period, primeDay, withPeriods } from "@/views/salesEvent.fixture";
import type { SalesEventListResponse, SalesEventWithPeriods } from "@/types/api";

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

describe("SalesEventView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesEvents).mockReset().mockResolvedValue(list());
        vi.mocked(fetchSalesEvent).mockReset();
        vi.mocked(createSalesEvent).mockReset();
    });

    it("splits the events into mall tabs and shows the multiplier of that mall's channel", async () => {
        const summerSale = withPeriods([period(51, "2026-08-01", "2026-08-07", "ended", 1.8)], {
            id: 5,
            name: "サマーセール",
            note: null,
            malls: [
                { value: "rakuten", label: "楽天市場" },
                { value: "yahoo", label: "Yahoo!ショッピング" },
            ],
        });
        vi.mocked(fetchSalesEvents).mockResolvedValue({ data: [...list().data, summerSale], meta });
        const { wrapper, router } = await mountAt("/sales-events");

        expect(wrapper.find("h1").text()).toBe("イベント");
        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.text())).toEqual(["楽天市場 3", "Amazon 1", "Yahoo!ショッピング 1", "au PAY マーケット 0"]);
        expect(wrapper.find('[data-testid="mall-tab-rakuten"]').attributes("aria-selected")).toBe("true");

        const rows = wrapper.findAll('[data-testid="event-row"]');
        expect(rows.map((row) => row.find('[data-testid="event-link"]').text())).toEqual(["楽天スーパーセール", "お買い物マラソン", "サマーセール"]);
        expect(rows[0]!.find('[data-testid="row-period"]').text()).toContain("2026/12/4〜12/7");
        expect(rows[0]!.find('[data-testid="row-multiplier"]').text()).toBe("4.67倍候補");
        expect(rows[0]!.find('[data-testid="row-counts"]').text()).toBe("3件（予定 1・終了 2）");
        // 次の期間が無いイベントは、最後の期間と実績の倍率を出す
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("2026/1/9〜1/16");
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("終了");
        expect(rows[1]!.find('[data-testid="row-multiplier"]').text()).toContain("実績");
        expect(rows[2]!.find('[data-testid="other-malls"]').text()).toBe("Yahoo!ショッピングでも開催");

        await wrapper.find('[data-testid="mall-tab-amazon"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ mall: "amazon" });
        expect(wrapper.findAll('[data-testid="event-link"]').map((link) => link.text())).toEqual(["プライムデー"]);
        expect(wrapper.find('[data-testid="row-multiplier"]').text()).toBe("3.20倍候補");

        await wrapper.find('[data-testid="mall-tab-yahoo"]').trigger("click");
        await flushPromises();
        expect(wrapper.findAll('[data-testid="event-link"]').map((link) => link.text())).toEqual(["サマーセール"]);
        expect(wrapper.find('[data-testid="other-malls"]').text()).toBe("楽天市場でも開催");

        await wrapper.find('[data-testid="mall-tab-au_pay"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="empty"]').text()).toContain("au PAY マーケットのイベントはまだありません。");
        wrapper.unmount();
    });

    it("opens the tab of the mall in the URL, and the event page with that mall when an event is pressed", async () => {
        vi.mocked(fetchSalesEvent).mockResolvedValue({ data: primeDay, meta });
        const { wrapper, router } = await mountAt("/sales-events?mall=amazon");

        expect(wrapper.find('[data-testid="mall-tab-amazon"]').attributes("aria-selected")).toBe("true");
        await wrapper.find('[data-testid="event-row"]').trigger("click");
        await flushPromises();

        expect(router.currentRoute.value.name).toBe("sales-event");
        expect(router.currentRoute.value.params.eventId).toBe("3");
        expect(router.currentRoute.value.query).toEqual({ mall: "amazon" });
        expect(fetchSalesEvent).toHaveBeenCalledWith(3);
        expect(wrapper.find("h1").text()).toContain("プライムデー");
        wrapper.unmount();
    });

    it("registers an event in the selected mall with its first period and opens its page", async () => {
        const created = withPeriods([period(31, "2026-11-24", "2026-12-01", "upcoming")], { id: 4, name: "ブラックフライデー", malls: [{ value: "amazon", label: "Amazon" }], channels: ["amazon"] });
        vi.mocked(createSalesEvent).mockResolvedValue({ data: created, meta });
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [...list().data, created], meta });
        vi.mocked(fetchSalesEvent).mockResolvedValue({ data: created, meta });
        const { wrapper, router } = await mountAt("/sales-events?mall=amazon");

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        expect((wrapper.find('[data-testid="event-mall-amazon"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.find('[data-testid="event-mall-rakuten"]').element as HTMLInputElement).checked).toBe(false);
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        expect(wrapper.find('[data-testid="event-channels"]').text()).toContain("チャネル：Amazon");
        await wrapper.find('[data-testid="event-starts-on"]').setValue("2026-11-24");
        await wrapper.find('[data-testid="event-ends-on"]').setValue("2026-12-01");
        expect(wrapper.find('[data-testid="event-days"]').text()).toBe("8日間");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledWith({ name: "ブラックフライデー", malls: ["amazon"], note: null, period: { starts_on: "2026-11-24", ends_on: "2026-12-01", note: null } });
        expect(router.currentRoute.value.name).toBe("sales-event");
        expect(router.currentRoute.value.params.eventId).toBe("4");
        expect(router.currentRoute.value.query).toEqual({ mall: "amazon" });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="message"]').text()).toContain("ブラックフライデーを登録しました。");
        wrapper.unmount();
    });

    it("keeps the event dialog open with a spinner until the registered event appears in the list", async () => {
        const created = withPeriods([], { id: 4, name: "ブラックフライデー", note: null });
        let finishCreate!: (response: { data: SalesEventWithPeriods; meta: typeof meta }) => void;
        let finishRefresh!: (result: SalesEventListResponse) => void;
        vi.mocked(createSalesEvent).mockReturnValue(new Promise((resolve) => (finishCreate = resolve)));
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockReturnValueOnce(new Promise((resolve) => (finishRefresh = resolve)));
        vi.mocked(fetchSalesEvent).mockResolvedValue({ data: created, meta });
        const { wrapper, router } = await mountAt("/sales-events");

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        await wrapper.find("form").trigger("submit");

        expect(wrapper.find("form").attributes("aria-busy")).toBe("true");
        expect(wrapper.find('[data-testid="event-save"] svg').classes()).toContain("animate-spin");
        expect(wrapper.find('[data-testid="event-save"]').text()).toBe("登録中…");
        expect(wrapper.find('[role="dialog"] button[aria-label="閉じる"]').attributes("disabled")).toBeDefined();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);

        finishCreate({ data: created, meta });
        await flushPromises();
        expect(fetchSalesEvents).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="event-save"] svg').exists()).toBe(true);

        finishRefresh({ data: [...list().data, created], meta });
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(router.currentRoute.value.params.eventId).toBe("4");
        expect(wrapper.find('[data-testid="message"]').text()).toContain("期間を追加してください。");
        wrapper.unmount();
    });

    it("retries the event list refresh without registering the event again", async () => {
        const created = withPeriods([], { id: 4, name: "ブラックフライデー", note: null });
        vi.mocked(createSalesEvent).mockResolvedValue({ data: created, meta });
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [...list().data, created], meta });
        vi.mocked(fetchSalesEvent).mockResolvedValue({ data: created, meta });
        const { wrapper, router } = await mountAt("/sales-events");

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="event-save-error"]').text()).toContain("一覧を更新できませんでした");
        expect(wrapper.find('[data-testid="event-save"]').text()).toBe("一覧を再読み込み");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(router.currentRoute.value.name).toBe("sales-event");
        wrapper.unmount();
    });
});
