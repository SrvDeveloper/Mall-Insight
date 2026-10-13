import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SalesEventView from "@/views/SalesEventView.vue";
import { createSalesEvent, deleteSalesEvent, fetchSalesEvent, fetchSalesEvents, saveSalesEventMultipliers, updateSalesEvent } from "@/api/salesEvents";
import { ApiError } from "@/api/client";
import type { SalesEvent, SalesEventDetailResponse, SalesEventListResponse } from "@/types/api";

vi.mock("@/api/salesEvents", () => ({
    fetchSalesEvents: vi.fn(),
    fetchSalesEvent: vi.fn(),
    createSalesEvent: vi.fn(),
    updateSalesEvent: vi.fn(),
    deleteSalesEvent: vi.fn(),
    saveSalesEventMultipliers: vi.fn(),
}));

const meta = { recent_events: 3, min_expected: 10, max_days: 31 };

function event(id: number, startsOn: string, endsOn: string, status: SalesEvent["status"], overrides: Partial<SalesEvent> = {}): SalesEvent {
    return {
        id,
        name: "楽天スーパーセール",
        starts_on: startsOn,
        ends_on: endsOn,
        days: 4,
        malls: [{ value: "rakuten", label: "楽天市場" }],
        channels: ["boss"],
        note: null,
        status,
        status_label: { upcoming: "これから", ongoing: "開催中", ended: "終わった" }[status],
        created_by: "担当者",
        updated_by: "担当者",
        updated_at: "2026-10-07T09:00:00+09:00",
        ...overrides,
    };
}

const upcoming = event(3, "2026-12-04", "2026-12-07", "upcoming");
const ended = event(2, "2026-09-04", "2026-09-07", "ended");
const older = event(1, "2026-06-04", "2026-06-07", "ended");

function list(): SalesEventListResponse {
    return {
        data: [
            { ...upcoming, multipliers: [{ channel: "boss", label: "BOSS", kind: "candidate", multiplier: 4.67, basis_count: 2, override_count: 1 }] },
            { ...ended, multipliers: [{ channel: "boss", label: "BOSS", kind: "measured", multiplier: 4.67, basis_count: 0, override_count: 0 }] },
            { ...older, multipliers: [{ channel: "boss", label: "BOSS", kind: "measured", multiplier: null, basis_count: 0, override_count: 0 }] },
        ],
        meta,
    };
}

function upcomingDetail(override: number | null = null): SalesEventDetailResponse {
    return {
        data: {
            ...upcoming,
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
                        { id: 2, name: "楽天スーパーセール", starts_on: "2026-09-04", ends_on: "2026-09-07", expected: 12, actual: 56, ratio: 4.67 },
                        { id: 1, name: "楽天スーパーセール", starts_on: "2026-06-04", ends_on: "2026-06-07", expected: 12, actual: 56, ratio: 4.67 },
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

const endedDetail: SalesEventDetailResponse = {
    data: {
        ...ended,
        remaining_days: 0,
        channels: [
            {
                channel: "boss",
                label: "BOSS",
                kind: "measured",
                overall: 4.67,
                expected: 12,
                actual: 56,
                basis: [],
                note: null,
                items: [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", expected: 8, actual: 48, ratio: 6 }],
            },
        ],
    },
    meta,
};

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
        vi.mocked(fetchSalesEvent)
            .mockReset()
            .mockImplementation(async (id) => (id === 3 ? upcomingDetail(null) : endedDetail));
        vi.mocked(createSalesEvent).mockReset();
        vi.mocked(updateSalesEvent).mockReset();
        vi.mocked(deleteSalesEvent).mockReset();
        vi.mocked(saveSalesEventMultipliers).mockReset();
    });

    it("lists upcoming events with the candidate multiplier and switches to ended ones with how many times more they sold", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("イベント");
        let rows = wrapper.findAll('[data-testid="event-row"]');
        expect(rows).toHaveLength(1);
        expect(rows[0]!.text()).toContain("2026/12/4〜12/7");
        expect(rows[0]!.find('[data-testid="row-multiplier"]').text()).toContain("4.67倍");
        expect(rows[0]!.text()).toContain("過去2回から");
        expect(rows[0]!.text()).toContain("1品番は入力");

        await wrapper.find('[data-testid="filter-ended"]').trigger("click");
        rows = wrapper.findAll('[data-testid="event-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1]!.text()).toContain("測れません");
        wrapper.unmount();
    });

    it("opens the multipliers of an upcoming event below the list and saves the ones entered", async () => {
        vi.mocked(saveSalesEventMultipliers).mockResolvedValue(upcomingDetail(3.5));
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="event-row"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.event).toBe("3");
        const detail = wrapper.find('[data-testid="event-detail"]');
        expect(detail.find('[data-testid="candidate-summary"]').text()).toContain("全体の候補 4.67倍");
        expect(detail.findAll('[data-testid="candidate-basis"] li')).toHaveLength(2);
        const rows = detail.findAll('[data-testid="candidate-row"]');
        expect(rows[0]!.find('[data-testid="candidate-value"]').text()).toBe("6.00倍");
        expect(rows[1]!.find('[data-testid="uses-overall"]').exists()).toBe(true);
        expect(rows[0]!.find('[data-testid="extra"]').text()).toBe("+40");
        expect(detail.find('[data-testid="save-multipliers"]').attributes("disabled")).toBeDefined();

        // 小数3桁は入れられない
        await rows[0]!.find('[data-testid="multiplier-input"]').setValue("2.125");
        expect(wrapper.find('[data-testid="invalid-note"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="save-multipliers"]').attributes("disabled")).toBeDefined();

        await rows[0]!.find('[data-testid="multiplier-input"]').setValue("3.5");
        await wrapper.find('[data-testid="save-multipliers"]').trigger("click");
        await flushPromises();
        expect(saveSalesEventMultipliers).toHaveBeenCalledWith(3, [{ item_no: "fl-01", channel: "boss", multiplier: 3.5 }]);
        expect(wrapper.find('[data-testid="saved-note"]').text()).toContain("1品番の倍率を保存しました。");
        expect((wrapper.find('[data-testid="multiplier-input"]').element as HTMLInputElement).value).toBe("3.5");
        // 一覧も読み込み直す
        expect(fetchSalesEvents).toHaveBeenCalledTimes(2);

        // 全品番に同じ倍率を入れ、すべて候補に戻す
        await wrapper.find('[data-testid="bulk-boss"]').setValue("2");
        await wrapper.find('[data-testid="bulk-apply-boss"]').trigger("click");
        expect(wrapper.findAll('[data-testid="multiplier-input"]').map((input) => (input.element as HTMLInputElement).value)).toEqual(["2", "2"]);
        await wrapper.find('[data-testid="clear-boss"]').trigger("click");
        expect(wrapper.findAll('[data-testid="multiplier-input"]').map((input) => (input.element as HTMLInputElement).value)).toEqual(["", ""]);

        await wrapper.find('[data-testid="detail-close"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query.event).toBeUndefined();
        expect(wrapper.find('[data-testid="event-detail"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("shows how many times more an ended event sold without letting the multipliers be changed", async () => {
        const { wrapper } = await mountView("/sales-events?event=2");

        const detail = wrapper.find('[data-testid="event-detail"]');
        expect(detail.find('[data-testid="measured-summary"]').text()).toContain("普段の 4.67倍 売れました（普段なら 12個のところ 56個）");
        expect(detail.find('[data-testid="measured-row"]').text()).toContain("6.00倍");
        expect(detail.find('[data-testid="multiplier-input"]').exists()).toBe(false);
        expect(detail.find('[data-testid="save-multipliers"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("registers a copy of an event with only the period entered again", async () => {
        vi.mocked(createSalesEvent).mockResolvedValue(event(4, "2027-03-04", "2027-03-11", "upcoming"));
        const { wrapper } = await mountView();

        await wrapper.find('[data-testid="copy-event"]').trigger("click");
        expect(wrapper.find("#event-form-title").text()).toBe("イベントをコピーして登録");
        expect((wrapper.find('[data-testid="event-name"]').element as HTMLInputElement).value).toBe("楽天スーパーセール");
        expect((wrapper.find('[data-testid="event-starts-on"]').element as HTMLInputElement).value).toBe("");
        expect((wrapper.find('[data-testid="event-mall-rakuten"]').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.find('[data-testid="event-channels"]').text()).toContain("チャネル：BOSS");

        await wrapper.find('[data-testid="event-starts-on"]').setValue("2027-03-04");
        await wrapper.find('[data-testid="event-ends-on"]').setValue("2027-03-11");
        expect(wrapper.find('[data-testid="event-days"]').text()).toBe("8日間");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledWith({ name: "楽天スーパーセール", starts_on: "2027-03-04", ends_on: "2027-03-11", malls: ["rakuten"], note: null });
        expect(wrapper.find('[data-testid="message"]').text()).toContain("楽天スーパーセール（2027/3/4〜3/11）を登録しました。");
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it("shows why an event could not be saved", async () => {
        vi.mocked(createSalesEvent).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { starts_on: ["同じ名前のイベント（2026/12/4〜2026/12/7）と期間が重なっています。"] }));
        const { wrapper } = await mountView();

        await wrapper.find('[data-testid="add-event"]').trigger("click");
        await wrapper.find('[data-testid="event-name"]').setValue("楽天スーパーセール");
        await wrapper.find('[data-testid="event-starts-on"]').setValue("2026-12-05");
        await wrapper.find('[data-testid="event-ends-on"]').setValue("2026-12-06");
        await wrapper.find('[data-testid="event-mall-yahoo"]').setValue(true);
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(createSalesEvent).toHaveBeenCalledWith({ name: "楽天スーパーセール", starts_on: "2026-12-05", ends_on: "2026-12-06", malls: ["yahoo"], note: null });
        expect(wrapper.find('[data-testid="starts-on-error"]').text()).toBe("同じ名前のイベント（2026/12/4〜2026/12/7）と期間が重なっています。");
        wrapper.unmount();
    });

    it("deletes an event after confirming", async () => {
        vi.spyOn(window, "confirm").mockReturnValue(true);
        vi.mocked(deleteSalesEvent).mockResolvedValue();
        const { wrapper } = await mountView();

        await wrapper.find('[data-testid="delete-event"]').trigger("click");
        await flushPromises();

        expect(deleteSalesEvent).toHaveBeenCalledWith(3);
        expect(wrapper.find('[data-testid="message"]').text()).toContain("を削除しました。");
        wrapper.unmount();
    });
});
