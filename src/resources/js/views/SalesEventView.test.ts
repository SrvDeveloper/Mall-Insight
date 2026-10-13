import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SalesEventView from "@/views/SalesEventView.vue";
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
import type { SalesEventListResponse, SalesEventPeriodDetailResponse, SalesEventPeriodSummary, SalesEventStatus, SalesEventWithPeriods } from "@/types/api";

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
const STATUS_LABELS: Record<SalesEventStatus, string> = { upcoming: "これから", ongoing: "開催中", ended: "終わった" };

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
        expect(rows[0]!.find('[data-testid="row-counts"]').text()).toBe("3件（これから 1・終わった 2）");

        // 次の期間が無いイベントは、最後の期間と実績の倍率を出す
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("2026/1/9〜1/16");
        expect(rows[1]!.find('[data-testid="row-period"]').text()).toContain("終了");
        expect(rows[1]!.find('[data-testid="row-period"]').text()).not.toContain("終わった");
        expect(rows[1]!.find('[data-testid="row-multiplier"]').text()).toContain("実績");
        wrapper.unmount();
    });

    it("shows the periods of an event below the list, adds a period and edits one in place", async () => {
        vi.mocked(createSalesEventPeriod).mockResolvedValue({ ...salePeriods[2]!, id: 14, starts_on: "2027-03-04", ends_on: "2027-03-11" });
        vi.mocked(updateSalesEventPeriod).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { starts_on: ["このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。"] }));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="event-row"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.event).toBe("1");
        const section = wrapper.find('[data-testid="event-periods"]');
        expect(section.find("h2").text()).toBe("楽天スーパーセール");
        expect(section.findAll('[data-testid="period-row"]')).toHaveLength(3);

        await wrapper.find('[data-testid="add-starts-on"]').setValue("2027-03-04");
        await wrapper.find('[data-testid="add-ends-on"]').setValue("2027-03-11");
        expect(wrapper.find('[data-testid="add-days"]').text()).toBe("8日間");
        await wrapper.find('[data-testid="period-add"]').trigger("submit");
        await flushPromises();
        expect(createSalesEventPeriod).toHaveBeenCalledWith(1, { starts_on: "2027-03-04", ends_on: "2027-03-11", note: null });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセールに 2027/3/4〜3/11 の期間を追加しました。");
        expect(fetchSalesEvents).toHaveBeenCalledTimes(2);

        await wrapper.findAll('[data-testid="period-edit"]')[2]!.trigger("click");
        await wrapper.find('[data-testid="edit-starts-on"]').setValue("2026-09-05");
        await wrapper.find('[data-testid="edit-save"]').trigger("click");
        await flushPromises();
        expect(updateSalesEventPeriod).toHaveBeenCalledWith(13, { starts_on: "2026-09-05", ends_on: "2026-12-07", note: null });
        expect(wrapper.find('[data-testid="edit-error"]').text()).toBe("このイベントの別の期間（2026/9/4〜2026/9/7）と重なっています。");
        wrapper.unmount();
    });

    it("opens the multipliers of an upcoming period and saves the ones entered for that period", async () => {
        vi.mocked(saveSalesEventPeriodMultipliers).mockResolvedValue(periodDetail(3.5));
        const { wrapper, router } = await mountView("/sales-events?event=1");

        await wrapper.findAll('[data-testid="period-row"]')[2]!.trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ event: "1", period: "13" });
        expect(fetchSalesEventPeriod).toHaveBeenCalledWith(13);
        const detail = wrapper.find('[data-testid="event-detail"]');
        expect(detail.find('[data-testid="candidate-summary"]').text()).toContain("このイベントの過去の期間 2回");
        expect(detail.findAll('[data-testid="candidate-basis"] li')).toHaveLength(2);

        await detail.findAll('[data-testid="multiplier-input"]')[0]!.setValue("3.5");
        await wrapper.find('[data-testid="save-multipliers"]').trigger("click");
        await flushPromises();
        expect(saveSalesEventPeriodMultipliers).toHaveBeenCalledWith(13, [{ item_no: "fl-01", channel: "boss", multiplier: 3.5 }]);
        expect(wrapper.find('[data-testid="saved-note"]').text()).toContain("1品番の倍率を保存しました。");

        // イベントを閉じると期間の倍率も閉じる
        await wrapper.find('[data-testid="periods-close"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({});
        expect(wrapper.find('[data-testid="event-detail"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("registers an event with its first period and opens its periods", async () => {
        vi.mocked(createSalesEvent).mockResolvedValue({ data: withPeriods([period(31, "2026-11-24", "2026-12-01", "upcoming")], { id: 3, name: "ブラックフライデー" }), meta });
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
