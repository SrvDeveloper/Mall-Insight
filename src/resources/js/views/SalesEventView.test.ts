import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SalesEventView from "@/views/SalesEventView.vue";
import SalesEventPeriodDetail from "@/components/salesEvent/SalesEventPeriodDetail.vue";
import {
    createSalesEvent,
    createSalesEventPeriod,
    deleteSalesEvent,
    deleteSalesEventPeriod,
    fetchSalesEventPeriod,
    fetchSalesEvents,
    saveSalesEventPeriodMultipliers,
    updateSalesEvent,
    updateSalesEventPeriod,
} from "@/api/salesEvents";
import { ApiError } from "@/api/client";
import type { SalesEventListResponse, SalesEventPeriod, SalesEventPeriodDetailResponse, SalesEventPeriodSummary, SalesEventStatus, SalesEventWithPeriods } from "@/types/api";

vi.mock("@/api/salesEvents", () => ({
    fetchSalesEvents: vi.fn(),
    createSalesEvent: vi.fn(),
    updateSalesEvent: vi.fn(),
    deleteSalesEvent: vi.fn(),
    createSalesEventPeriod: vi.fn(),
    updateSalesEventPeriod: vi.fn(),
    deleteSalesEventPeriod: vi.fn(),
    fetchSalesEventPeriod: vi.fn(),
    saveSalesEventPeriodMultipliers: vi.fn(),
}));

const meta = { recent_events: 3, min_expected: 10, max_days: 31 };
const STATUS_LABELS: Record<SalesEventStatus, string> = { upcoming: "これから", ongoing: "開催中", ended: "終了" };

function period(id: number, startsOn: string, endsOn: string, status: SalesEventStatus, multiplier: number | null = 4.67): SalesEventPeriodSummary {
    return {
        id,
        event_id: 1,
        starts_on: startsOn,
        ends_on: endsOn,
        days: 4,
        note: null,
        status,
        status_label: STATUS_LABELS[status],
        updated_by: "担当者",
        updated_at: null,
        multipliers: [{ channel: "boss", label: "BOSS", kind: status === "ended" ? "measured" : "candidate", multiplier, basis_count: status === "ended" ? 0 : 2, override_count: 0 }],
    };
}

const sale = {
    id: 1,
    name: "楽天スーパーセール",
    malls: [{ value: "rakuten" as const, label: "楽天市場" }],
    channels: ["boss" as const],
    note: "4日20時から",
    created_by: "担当者",
    updated_by: "担当者",
    updated_at: null,
};

function withPeriods(periods: SalesEventPeriodSummary[], overrides: Partial<SalesEventWithPeriods> = {}): SalesEventWithPeriods {
    return {
        ...sale,
        periods,
        next_period: periods.find((item) => item.status !== "ended") ?? null,
        last_period: periods.filter((item) => item.status === "ended").at(-1) ?? null,
        upcoming_count: periods.filter((item) => item.status !== "ended").length,
        ended_count: periods.filter((item) => item.status === "ended").length,
        ...overrides,
    };
}

const salePeriods = [period(11, "2026-06-04", "2026-06-07", "ended"), period(12, "2026-09-04", "2026-09-07", "ended"), period(13, "2026-12-04", "2026-12-07", "upcoming")];
const marathon = withPeriods([period(21, "2026-01-09", "2026-01-16", "ended", 1.4)], { id: 2, name: "お買い物マラソン", note: null });

function list(): SalesEventListResponse {
    return { data: [withPeriods(salePeriods), marathon], meta };
}

function periodDetail(override: number | null = null): SalesEventPeriodDetailResponse {
    const summary = Object.fromEntries(Object.entries(salePeriods[2]!).filter(([key]) => key !== "multipliers")) as Omit<SalesEventPeriodSummary, "multipliers">;
    return {
        data: {
            ...summary,
            event: sale,
            remaining_days: 4,
            channels: [
                {
                    channel: "boss",
                    label: "BOSS",
                    kind: "candidate",
                    overall: 4.67,
                    expected: 24,
                    actual: 112,
                    basis: [
                        { id: 12, name: "楽天スーパーセール", note: null, starts_on: "2026-09-04", ends_on: "2026-09-07", expected: 12, actual: 56, ratio: 4.67 },
                        { id: 11, name: "楽天スーパーセール", note: null, starts_on: "2026-06-04", ends_on: "2026-06-07", expected: 12, actual: 56, ratio: 4.67 },
                    ],
                    note: null,
                    items: [
                        {
                            item_no: "fl-01",
                            brand: "FEELLIFE",
                            category: "老眼鏡",
                            candidate: 6,
                            uses_overall: false,
                            past_expected: 16,
                            past_actual: 96,
                            override,
                            multiplier: override ?? 6,
                            base_average: 2,
                            usual: 8,
                            extra: 40,
                        },
                        {
                            item_no: "si-13",
                            brand: "FEELLIFE",
                            category: "調光老眼鏡",
                            candidate: 4.67,
                            uses_overall: true,
                            past_expected: 8,
                            past_actual: 16,
                            override: null,
                            multiplier: 4.67,
                            base_average: 1,
                            usual: 4,
                            extra: 14.68,
                        },
                    ],
                },
            ],
        },
        meta,
    };
}

async function mountView(path = "/sales-events") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: SalesEventView }] });
    await router.push(path);
    const wrapper = mount(SalesEventView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

describe("SalesEventView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesEvents).mockReset().mockResolvedValue(list());
        vi.mocked(fetchSalesEventPeriod).mockReset().mockResolvedValue(periodDetail());
        for (const mock of [createSalesEvent, updateSalesEvent, deleteSalesEvent, createSalesEventPeriod, updateSalesEventPeriod, deleteSalesEventPeriod, saveSalesEventPeriodMultipliers]) {
            vi.mocked(mock).mockReset();
        }
    });

    it("lists upcoming and ended events together with their period details", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("イベント");
        expect(wrapper.find('[aria-label="絞り込み"]').exists()).toBe(false);
        const rows = wrapper.findAll('[data-testid="event-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.find('[data-testid="row-period"]').text()).toContain("2026/12/4〜12/7");
        expect(rows[0]!.find('[data-testid="row-multiplier"]').text()).toContain("4.67倍");
        expect(rows[0]!.find('[data-testid="row-counts"]').text()).toBe("3件（予定 1・終了 2）");

        // 次の期間が無いイベントは、最後の期間と実績の倍率を出す
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("2026/1/9〜1/16");
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("終了");
        expect(rows[1]!.find('[data-testid="row-period"]').text()).not.toContain("終わった");
        expect(rows[1]!.find('[data-testid="row-multiplier"]').text()).toContain("実績");
        wrapper.unmount();
    });

    it("shows the periods of an event below the list, adds a period and edits one in place", async () => {
        const added = { ...salePeriods[2]!, id: 14, starts_on: "2027-03-04", ends_on: "2027-03-11", days: 8 };
        vi.mocked(createSalesEventPeriod).mockResolvedValue(added);
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [withPeriods([...salePeriods, added]), marathon], meta });
        vi.mocked(updateSalesEventPeriod).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { starts_on: ["このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。"] }));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="event-row"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.event).toBe("1");
        const section = wrapper.find('[data-testid="event-periods"]');
        expect(section.find("h2").text()).toBe("楽天スーパーセール");
        expect(section.findAll('[data-testid="period-row"]')).toHaveLength(3);
        expect(section.findAll('[data-testid="period-row"]')[0]!.text()).toContain("終了");
        expect(wrapper.find('[data-testid="event-workspace"]').classes()).toContain("xl:grid-cols-12");
        expect(wrapper.find('[data-testid="period-detail-placeholder"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        expect(wrapper.find('[role="dialog"]').attributes("aria-modal")).toBe("true");
        await wrapper.find('[data-testid="add-starts-on"]').setValue("2027-03-04");
        await wrapper.find('[data-testid="add-ends-on"]').setValue("2027-03-11");
        expect(wrapper.find('[data-testid="add-days"]').text()).toBe("8日間");
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();
        expect(createSalesEventPeriod).toHaveBeenCalledWith(1, { starts_on: "2027-03-04", ends_on: "2027-03-11", note: null });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセールに 2027/3/4〜3/11 の期間を追加しました。");
        expect(fetchSalesEvents).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="period-row"]')).toHaveLength(4);

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        await wrapper.find('[data-testid="period-add-close"]').trigger("click");
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);

        await wrapper.findAll('[data-testid="period-edit"]')[2]!.trigger("click");
        await wrapper.find('[data-testid="edit-starts-on"]').setValue("2026-09-05");
        await wrapper.find('[data-testid="edit-save"]').trigger("click");
        await flushPromises();
        expect(updateSalesEventPeriod).toHaveBeenCalledWith(13, { starts_on: "2026-09-05", ends_on: "2026-12-07", note: null });
        expect(wrapper.find('[data-testid="edit-error"]').text()).toBe("このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。");
        wrapper.unmount();
    });

    it("keeps the add dialog open and shows a spinner until the new period appears in the list", async () => {
        const added = { ...salePeriods[2]!, id: 14, starts_on: "2027-03-04", ends_on: "2027-03-11", days: 8 };
        let finishCreate!: (period: SalesEventPeriod) => void;
        let finishRefresh!: (result: SalesEventListResponse) => void;
        vi.mocked(createSalesEventPeriod).mockReturnValue(new Promise((resolve) => (finishCreate = resolve)));
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockReturnValueOnce(new Promise((resolve) => (finishRefresh = resolve)));
        const { wrapper } = await mountView("/sales-events?event=1");

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        await wrapper.find('[data-testid="add-starts-on"]').setValue("2027-03-04");
        await wrapper.find('[data-testid="add-ends-on"]').setValue("2027-03-11");
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
        expect(fetchSalesEvents).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="add-submit"] svg').exists()).toBe(true);

        finishRefresh({ data: [withPeriods([...salePeriods, added]), marathon], meta });
        await flushPromises();
        expect(wrapper.find('[data-testid="period-add"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="period-row"]')).toHaveLength(4);
        wrapper.unmount();
    });

    it("retries the list refresh without creating the period again", async () => {
        const added = { ...salePeriods[2]!, id: 14, starts_on: "2027-03-04", ends_on: "2027-03-11", days: 8 };
        vi.mocked(createSalesEventPeriod).mockResolvedValue(added);
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [withPeriods([...salePeriods, added]), marathon], meta });
        const { wrapper } = await mountView("/sales-events?event=1");

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        await wrapper.find('[data-testid="add-starts-on"]').setValue("2027-03-04");
        await wrapper.find('[data-testid="add-ends-on"]').setValue("2027-03-11");
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
        const { wrapper } = await mountView("/sales-events?event=1");

        await wrapper.find('[data-testid="open-period-add"]').trigger("click");
        await wrapper.find('[data-testid="add-starts-on"]').setValue("2026-09-04");
        await wrapper.find('[data-testid="add-ends-on"]').setValue("2026-09-11");
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="add-error"]').text()).toBe("別の期間と重なっています。");
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("opens the multipliers of an upcoming period and saves the ones entered for that period", async () => {
        vi.mocked(saveSalesEventPeriodMultipliers).mockResolvedValue(periodDetail(3.5));
        const { wrapper, router } = await mountView("/sales-events?event=1");

        await wrapper.findAll('[data-testid="period-row"]')[2]!.trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ event: "1", period: "13" });
        expect(wrapper.find('[data-testid="event-workspace"]').classes()).toContain("xl:grid-cols-12");
        expect(wrapper.find('[data-testid="period-detail-column"] [data-testid="event-detail"]').exists()).toBe(true);
        expect(fetchSalesEventPeriod).toHaveBeenCalledWith(13);
        const detail = wrapper.find('[data-testid="event-detail"]');
        expect(detail.find('[data-testid="candidate-summary"]').text()).toContain("このイベントの過去の期間 2回");
        expect(detail.findAll('[data-testid="candidate-basis"] li')).toHaveLength(2);

        await detail.findAll('[data-testid="multiplier-input"]')[0]!.setValue("3.5");
        await wrapper.find('[data-testid="save-multipliers"]').trigger("click");
        await flushPromises();
        expect(saveSalesEventPeriodMultipliers).toHaveBeenCalledWith(13, [{ item_no: "fl-01", channel: "boss", multiplier: 3.5 }]);
        expect(wrapper.find('[data-testid="saved-note"]').text()).toContain("1品番の倍率を保存しました。");

        await detail.find('[data-testid="detail-close"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ event: "1" });
        expect(wrapper.find('[data-testid="event-workspace"]').classes()).toContain("xl:grid-cols-12");
        expect(wrapper.find('[data-testid="period-detail-placeholder"]').exists()).toBe(true);

        // イベントを閉じると期間の倍率も閉じる
        await wrapper.find('[data-testid="periods-close"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({});
        expect(wrapper.find('[data-testid="event-detail"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("shows ended period items in descending change rate order, with unmeasurable items last", async () => {
        const endedDetail = periodDetail();
        endedDetail.data.status = "ended";
        endedDetail.data.status_label = "終了";
        endedDetail.data.channels = [
            {
                channel: "boss",
                label: "BOSS",
                kind: "measured",
                overall: 2,
                expected: 10,
                actual: 20,
                basis: [],
                note: null,
                items: [
                    { item_no: "fl-02", brand: "FEELLIFE", category: "老眼鏡", expected: 5, actual: 10, ratio: 2 },
                    { item_no: "fl-04", brand: "FEELLIFE", category: "老眼鏡", expected: null, actual: null, ratio: null },
                    { item_no: "fl-03", brand: "FEELLIFE", category: "老眼鏡", expected: 1, actual: 6, ratio: 6 },
                    { item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", expected: 1, actual: 6, ratio: 6 },
                ],
            },
        ];
        const wrapper = mount(SalesEventPeriodDetail, { props: { detail: endedDetail, isLoading: false } });

        const rows = wrapper.findAll('[data-testid="measured-row"]');
        expect(rows.map((row) => row.find("th span").text())).toEqual(["fl-01", "fl-03", "fl-02", "fl-04"]);
        expect(rows.map((row) => row.find("td:last-child").text())).toEqual(["6.00倍", "6.00倍", "2.00倍", "—"]);
        expect(endedDetail.data.channels[0]?.items.map((item) => item.item_no)).toEqual(["fl-02", "fl-04", "fl-03", "fl-01"]);
        wrapper.unmount();
    });

    it("registers an event with its first period and opens its periods", async () => {
        const created = withPeriods([period(31, "2026-11-24", "2026-12-01", "upcoming")], { id: 3, name: "ブラックフライデー" });
        vi.mocked(createSalesEvent).mockResolvedValue({ data: created, meta });
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [...list().data, created], meta });
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        await wrapper.find('[data-testid="event-mall-amazon"]').setValue(true);
        expect(wrapper.find('[data-testid="event-channels"]').text()).toContain("チャネル：Amazon");
        await wrapper.find('[data-testid="event-starts-on"]').setValue("2026-11-24");
        await wrapper.find('[data-testid="event-ends-on"]').setValue("2026-12-01");
        expect(wrapper.find('[data-testid="event-days"]').text()).toBe("8日間");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledWith({ name: "ブラックフライデー", malls: ["amazon"], note: null, period: { starts_on: "2026-11-24", ends_on: "2026-12-01", note: null } });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("ブラックフライデーを登録しました。");
        expect(router.currentRoute.value.query.event).toBe("3");
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="event-row"]')).toHaveLength(3);
        wrapper.unmount();
    });

    it("keeps the event dialog open with a spinner until the registered event appears in the list", async () => {
        const created = withPeriods([], { id: 3, name: "ブラックフライデー", note: null });
        let finishCreate!: (response: { data: SalesEventWithPeriods; meta: typeof meta }) => void;
        let finishRefresh!: (result: SalesEventListResponse) => void;
        vi.mocked(createSalesEvent).mockReturnValue(new Promise((resolve) => (finishCreate = resolve)));
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockReturnValueOnce(new Promise((resolve) => (finishRefresh = resolve)));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        await wrapper.find('[data-testid="event-mall-amazon"]').setValue(true);
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
        expect(wrapper.findAll('[data-testid="event-row"]')).toHaveLength(3);
        expect(router.currentRoute.value.query.event).toBe("3");
        wrapper.unmount();
    });

    it("retries the event list refresh without registering the event again", async () => {
        const created = withPeriods([], { id: 3, name: "ブラックフライデー", note: null });
        vi.mocked(createSalesEvent).mockResolvedValue({ data: created, meta });
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [...list().data, created], meta });
        const { wrapper } = await mountView();

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("ブラックフライデー");
        await wrapper.find('[data-testid="event-mall-amazon"]').setValue(true);
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(wrapper.find('[data-testid="event-save-error"]').text()).toContain("一覧を更新できませんでした");
        expect(wrapper.find('[data-testid="event-save"]').text()).toBe("一覧を再読み込み");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="event-row"]')).toHaveLength(3);
        wrapper.unmount();
    });

    it("edits an event without periods and shows why a name cannot be used", async () => {
        vi.mocked(updateSalesEvent).mockRejectedValue(
            new ApiError("入力内容を確認してください。", 422, { name: ["同じ名前のイベント（お買い物マラソン）が登録されています。期間はそのイベントに追加してください。"] }),
        );
        const { wrapper } = await mountView();

        await wrapper.find('[data-testid="edit-event"]').trigger("click");
        expect(wrapper.find('[data-testid="event-starts-on"]').exists()).toBe(false);
        await wrapper.find('[data-testid="event-name"]').setValue("お買い物マラソン");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(updateSalesEvent).toHaveBeenCalledWith(1, { name: "お買い物マラソン", malls: ["rakuten"], note: "4日20時から", period: null });
        expect(wrapper.find('[data-testid="name-error"]').text()).toContain("同じ名前のイベント（お買い物マラソン）");
        wrapper.unmount();
    });

    it("shows an edited event in the list before closing its dialog", async () => {
        const updated = withPeriods(salePeriods, { name: "楽天スーパーセール改" });
        vi.mocked(updateSalesEvent).mockResolvedValue({ data: updated, meta });
        vi.mocked(fetchSalesEvents)
            .mockResolvedValueOnce(list())
            .mockResolvedValueOnce({ data: [updated, marathon], meta });
        const { wrapper } = await mountView("/sales-events?event=1");

        await wrapper.find('[data-testid="periods-edit-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("楽天スーパーセール改");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="event-row"]').text()).toContain("楽天スーパーセール改");
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセール改を保存しました。");
        wrapper.unmount();
    });

    it("deletes a period and an event after confirming", async () => {
        vi.spyOn(window, "confirm").mockReturnValue(true);
        vi.mocked(deleteSalesEventPeriod).mockResolvedValue();
        vi.mocked(deleteSalesEvent).mockResolvedValue();
        const { wrapper } = await mountView("/sales-events?event=1");

        await wrapper.findAll('[data-testid="period-delete"]')[0]!.trigger("click");
        await flushPromises();
        expect(deleteSalesEventPeriod).toHaveBeenCalledWith(11);

        await wrapper.find('[data-testid="delete-event"]').trigger("click");
        await flushPromises();
        expect(deleteSalesEvent).toHaveBeenCalledWith(1);
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセールを削除しました。");
        wrapper.unmount();
    });
});
