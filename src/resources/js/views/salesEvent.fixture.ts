import { defineComponent, h } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { RouterView, createMemoryHistory, createRouter } from "vue-router";
import SalesEventDetailView from "@/views/SalesEventDetailView.vue";
import SalesEventPeriodView from "@/views/SalesEventPeriodView.vue";
import SalesEventView from "@/views/SalesEventView.vue";
import type { SalesEventListResponse, SalesEventPeriodDetailResponse, SalesEventPeriodSummary, SalesEventStatus, SalesEventSummary, SalesEventWithPeriods } from "@/types/api";

/**
 * イベントの一覧・イベントの画面・期間の画面のテストで使うデータ。楽天スーパーセール（楽天市場、終了2・これから1）と
 * お買い物マラソン（楽天市場、終了1）、プライムデー（Amazon、これから1）。
 */

export const meta = { recent_events: 3, min_expected: 10, max_days: 31 };
const STATUS_LABELS: Record<SalesEventStatus, string> = { upcoming: "これから", ongoing: "開催中", ended: "終了" };

export function period(id: number, startsOn: string, endsOn: string, status: SalesEventStatus, multiplier: number | null = 4.67): SalesEventPeriodSummary {
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

export const sale = {
    id: 1,
    name: "楽天スーパーセール",
    malls: [{ value: "rakuten" as const, label: "楽天市場" }],
    channels: ["boss" as const],
    note: "4日20時から",
    created_by: "担当者",
    updated_by: "担当者",
    updated_at: null,
};

export function withPeriods(periods: SalesEventPeriodSummary[], overrides: Partial<SalesEventWithPeriods> = {}): SalesEventWithPeriods {
    return {
        ...sale,
        periods,
        next_period: periods.find((item) => item.status !== "ended") ?? null,
        last_period: periods.filter((item) => item.status === "ended").at(-1) ?? null,
        period_count: periods.length,
        upcoming_count: periods.filter((item) => item.status !== "ended").length,
        ended_count: periods.filter((item) => item.status === "ended").length,
        ...overrides,
    };
}

export const salePeriods = [period(11, "2026-06-04", "2026-06-07", "ended"), period(12, "2026-09-04", "2026-09-07", "ended"), period(13, "2026-12-04", "2026-12-07", "upcoming")];
export const marathon = withPeriods([period(21, "2026-01-09", "2026-01-16", "ended", 1.4)], { id: 2, name: "お買い物マラソン", note: null });

export const primeDay = withPeriods(
    [
        {
            ...period(41, "2026-07-08", "2026-07-11", "upcoming", 3.2),
            event_id: 3,
            multipliers: [{ channel: "amazon", label: "Amazon", kind: "candidate", multiplier: 3.2, basis_count: 1, override_count: 0 }],
        },
    ],
    {
        id: 3,
        name: "プライムデー",
        malls: [{ value: "amazon", label: "Amazon" }],
        channels: ["amazon"],
        note: null,
    },
);

/** 一覧の行（期間の一覧は付かない）。 */
export function summary(event: SalesEventWithPeriods): SalesEventSummary {
    return Object.fromEntries(Object.entries(event).filter(([key]) => key !== "periods")) as unknown as SalesEventSummary;
}

export function list(): SalesEventListResponse {
    return { data: [withPeriods(salePeriods), marathon, primeDay].map(summary), meta };
}

/** 3つの画面を持つルーターで、path の画面を開く。 */
export async function mountAt(path: string) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/sales-events", name: "sales-events", component: SalesEventView },
            { path: "/sales-events/:eventId(\\d+)", name: "sales-event", component: SalesEventDetailView },
            { path: "/sales-events/:eventId(\\d+)/periods/:periodId(\\d+)", name: "sales-event-period", component: SalesEventPeriodView },
        ],
    });
    await router.push(path);
    const wrapper = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

export function periodDetail(override: number | null = null): SalesEventPeriodDetailResponse {
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
