<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { deleteSalesEvent, fetchSalesEventPeriod, fetchSalesEvents } from "@/api/salesEvents";
import SalesEventForm from "@/components/salesEvent/SalesEventForm.vue";
import SalesEventPeriodDetail from "@/components/salesEvent/SalesEventPeriodDetail.vue";
import SalesEventPeriods from "@/components/salesEvent/SalesEventPeriods.vue";
import { formatMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";
import type { SalesEvent, SalesEventListResponse, SalesEventPeriod, SalesEventPeriodDetailResponse, SalesEventPeriodSummary, SalesEventWithPeriods } from "@/types/api";

/**
 * イベント（バックログ B-129、決定記録 K-112・K-114）。左メニュー「在庫試算」の需要予測の下に置く。
 * 担当者がイベント（キャンペーン）の名前・モールを登録し、開催する期間をイベントにいくつも登録する。期間の日は平均日販から除き、
 * これからの期間の日は、品番ごとの倍率で需要予測と在庫予測を増やす。倍率は、同じイベントの過去の期間で普段の何倍売れたかから
 * システムが候補を出し、担当者が期間ごとに直せる。
 *
 * - 一覧はイベントごと。次の期間（開催中かこれから）と最後に終了した期間、期間の数を出す。次の期間のあるイベントを先に、その初日の早い順に並べる。
 * - イベントを押すと、その期間を出し、期間を追加・編集・削除できる。期間を押すと、横に品番ごとの倍率を出す。
 *   選んだイベントと期間は URL に持たせる。
 */

const route = useRoute();
const router = useRouter();

const result = shallowRef<SalesEventListResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const message = ref<string | null>(null);
const queryId = (key: string): number | null => (typeof route.query[key] === "string" && /^\d+$/.test(route.query[key] as string) ? Number(route.query[key]) : null);
const selectedEventId = computed(() => queryId("event"));
const selectedPeriodId = computed(() => queryId("period"));

const periodDetail = shallowRef<SalesEventPeriodDetailResponse | null>(null);
const isPeriodLoading = ref(false);
const periodError = ref(false);

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchSalesEvents();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

let latestPeriod = 0;

async function loadPeriod(): Promise<void> {
    const id = selectedPeriodId.value;
    const requestId = ++latestPeriod;
    if (id === null) {
        periodDetail.value = null;
        return;
    }
    isPeriodLoading.value = true;
    periodError.value = false;
    try {
        const response = await fetchSalesEventPeriod(id);
        if (requestId === latestPeriod) {
            periodDetail.value = response;
        }
    } catch {
        if (requestId === latestPeriod) {
            periodError.value = true;
        }
    } finally {
        if (requestId === latestPeriod) {
            isPeriodLoading.value = false;
        }
    }
}

onMounted(() => {
    void load();
    void loadPeriod();
});
watch(selectedPeriodId, () => void loadPeriod());

const events = computed(() => result.value?.data ?? []);
const selectedEvent = computed(() => events.value.find((event) => event.id === selectedEventId.value) ?? null);
const maxDays = computed(() => result.value?.meta.max_days ?? 31);

const periodsSection = useTemplateRef<HTMLElement>("periodsSection");
const periodSection = useTemplateRef<HTMLElement>("periodSection");

async function selectEvent(event: SalesEventWithPeriods): Promise<void> {
    const isSame = selectedEventId.value === event.id;
    await router.replace({ query: { ...route.query, event: isSame ? undefined : String(event.id), period: undefined } });
    if (!isSame) {
        await nextTick();
        periodsSection.value?.scrollIntoView?.({ behavior: "smooth", block: "nearest" });
    }
}

async function selectPeriod(periodId: number): Promise<void> {
    const isSame = selectedPeriodId.value === periodId;
    await router.replace({ query: { ...route.query, period: isSame ? undefined : String(periodId) } });
    if (!isSame) {
        await nextTick();
        periodSection.value?.scrollIntoView?.({ behavior: "smooth", block: "nearest" });
    }
}

function closeEvent(): void {
    void router.replace({ query: { ...route.query, event: undefined, period: undefined } });
}

function closePeriod(): void {
    void router.replace({ query: { ...route.query, period: undefined } });
}

async function onPeriodsChanged(text: string): Promise<void> {
    message.value = text;
    await load();
    if (selectedPeriodId.value !== null && !selectedEvent.value?.periods.some((period) => period.id === selectedPeriodId.value)) {
        closePeriod();
    } else {
        void loadPeriod();
    }
}

async function refreshAfterPeriodAdd(period: SalesEventPeriod, text: string): Promise<void> {
    const refreshed = await fetchSalesEvents();
    if (!refreshed.data.some((event) => event.id === period.event_id && event.periods.some((item) => item.id === period.id))) {
        throw new Error("追加した期間が一覧に表示されていません。");
    }
    result.value = refreshed;
    message.value = text;
}

function onPeriodSaved(saved: SalesEventPeriodDetailResponse): void {
    periodDetail.value = saved;
    void load();
}

// イベントの登録・変更のモーダル
const editing = ref<{ event: SalesEvent | null } | null>(null);
const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeForm();
    }
}

async function openForm(event: SalesEvent | null): Promise<void> {
    editing.value = { event };
    message.value = null;
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    closeButton.value?.focus();
}

function closeForm(): void {
    editing.value = null;
    document.removeEventListener("keydown", onKeydown);
}

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

async function onSaved(saved: SalesEventWithPeriods): Promise<void> {
    const isEdit = editing.value?.event !== null;
    message.value = isEdit ? `${saved.name}を保存しました。` : `${saved.name}を登録しました。${saved.periods.length === 0 ? "期間を追加してください。" : "需要予測と在庫予測に反映されます。"}`;
    closeForm();
    await load();
    if (!isEdit) {
        await router.replace({ query: { ...route.query, event: String(saved.id), period: undefined } });
    } else {
        void loadPeriod();
    }
}

async function remove(event: SalesEventWithPeriods): Promise<void> {
    if (!window.confirm(`${event.name}を削除しますか？期間（${event.periods.length}件）と担当者が入れた倍率も消えます。`)) {
        return;
    }
    try {
        await deleteSalesEvent(event.id);
        message.value = `${event.name}を削除しました。`;
        if (selectedEventId.value === event.id) {
            closeEvent();
        }
        void load();
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

const statusClass = (period: SalesEventPeriodSummary): string =>
    period.status === "ongoing"
        ? "bg-emerald-50 text-emerald-800 ring-emerald-200"
        : period.status === "upcoming"
          ? "bg-sky-50 text-sky-800 ring-sky-200"
          : "bg-stone-100 text-stone-600 ring-stone-200";

/** 一覧の倍率に出す期間（次の期間、無ければ最後に終了した期間）。 */
const shownPeriod = (event: SalesEventWithPeriods): SalesEventPeriodSummary | null => event.next_period ?? event.last_period;
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / イベント</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">イベント</h1>
                <p class="max-w-3xl text-[13px] text-stone-600">
                    楽天スーパーセールやプライムデーなどのイベントを登録し、開催する期間を追加します。期間の日は平均日販から除き、これからの期間の日は品番ごとの倍率で需要予測を増やします。
                    倍率は、同じイベントの過去の期間で普段の何倍売れたかから候補を出します。過去の期間も追加すると、候補を出せるようになります。
                </p>
            </div>
            <button
                type="button"
                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700"
                data-testid="add-event"
                @click="openForm(null)"
            >
                <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M8 3v10M3 8h10" />
                </svg>
                イベントを登録
            </button>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status" data-testid="message">
            <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m3.5 8.5 3 3 6-7" />
            </svg>
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <h2 class="text-[13px] font-semibold text-stone-900">
                    登録済みのイベント <span class="ml-1 font-normal text-stone-500 tabular-nums">{{ result ? events.length : "" }}</span>
                </h2>
                <p class="text-[11px] text-stone-400">イベントを押すと、期間を下に出します</p>
            </div>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">イベントを表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="events.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                イベントはまだありません。
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-semibold text-stone-700 hover:bg-stone-100" @click="openForm(null)">イベントを登録</button>
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-240 border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">名前</th>
                            <th scope="col" class="px-3 py-2.5">モール</th>
                            <th scope="col" class="px-3 py-2.5">次の期間<span class="ml-1 font-normal text-stone-400">（無ければ最後の期間）</span></th>
                            <th scope="col" class="px-3 py-2.5">倍率</th>
                            <th scope="col" class="px-3 py-2.5">期間の数</th>
                            <th scope="col" class="px-3 py-2.5"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in events"
                            :key="event.id"
                            class="cursor-pointer *:border-t *:border-stone-100"
                            :class="selectedEventId === event.id ? 'bg-stone-100' : 'hover:bg-stone-50'"
                            :aria-selected="selectedEventId === event.id"
                            data-testid="event-row"
                            @click="selectEvent(event)"
                        >
                            <td class="px-4 py-3 align-top">
                                <button
                                    type="button"
                                    class="text-left font-semibold text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    :aria-expanded="selectedEventId === event.id"
                                    @click.stop="selectEvent(event)"
                                >
                                    {{ event.name }}
                                </button>
                                <p v-if="event.note" class="mt-0.5 max-w-56 truncate text-[11px] text-stone-500" :title="event.note">{{ event.note }}</p>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="flex flex-wrap gap-1">
                                    <span v-for="mall in event.malls" :key="mall.value" class="rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] whitespace-nowrap text-stone-600">{{
                                        mall.label
                                    }}</span>
                                </span>
                            </td>
                            <td class="px-3 py-3 align-top whitespace-nowrap tabular-nums" data-testid="row-period">
                                <template v-if="shownPeriod(event)">
                                    {{ periodLabel(shownPeriod(event)!.starts_on, shownPeriod(event)!.ends_on) }}
                                    <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="statusClass(shownPeriod(event)!)">{{ shownPeriod(event)!.status_label }}</span>
                                </template>
                                <span v-else class="text-xs text-amber-700">期間がありません</span>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span v-if="shownPeriod(event)" class="flex flex-col gap-0.5">
                                    <span
                                        v-for="multiplier in shownPeriod(event)!.multipliers"
                                        :key="multiplier.channel"
                                        class="text-xs whitespace-nowrap text-stone-600 tabular-nums"
                                        data-testid="row-multiplier"
                                    >
                                        <span class="inline-block w-14 text-stone-400">{{ multiplier.label }}</span>
                                        <strong class="font-semibold" :class="multiplier.multiplier === null ? 'text-stone-300' : 'text-stone-900'">{{
                                            formatMultiplier(multiplier.multiplier)
                                        }}</strong>
                                        <span class="ml-1 text-[11px] text-stone-400">{{ multiplier.kind === "candidate" ? (multiplier.basis_count === 0 ? "候補なし" : "候補") : "実績" }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-3 align-top text-xs whitespace-nowrap text-stone-600 tabular-nums" data-testid="row-counts">
                                {{ event.periods.length }}件<span class="ml-1 text-stone-400">（予定 {{ event.upcoming_count }}・終了 {{ event.ended_count }}）</span>
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap" @click.stop>
                                <button
                                    type="button"
                                    class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                                    data-testid="edit-event"
                                    @click="openForm(event)"
                                >
                                    編集
                                </button>
                                <button type="button" class="h-8 rounded-lg px-2.5 text-xs font-medium text-red-700 hover:bg-red-50" data-testid="delete-event" @click="remove(event)">削除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="selectedEvent" class="grid items-stretch gap-5 xl:h-[min(72vh,48rem)] xl:min-h-[28rem] xl:grid-cols-12" data-testid="event-workspace">
            <div ref="periodsSection" class="min-h-0 min-w-0 xl:col-span-5">
                <SalesEventPeriods
                    :event="selectedEvent"
                    :selected-period-id="selectedPeriodId"
                    :max-days="maxDays"
                    :refresh-after-add="refreshAfterPeriodAdd"
                    @select="selectPeriod"
                    @changed="onPeriodsChanged"
                    @edit-event="openForm(selectedEvent)"
                    @close="closeEvent"
                />
            </div>

            <div ref="periodSection" class="min-h-0 min-w-0 xl:col-span-7" data-testid="period-detail-column">
                <div
                    v-if="selectedPeriodId === null"
                    class="flex h-full min-h-48 flex-col items-center justify-center gap-2 rounded-xl border border-stone-200 bg-white px-6 py-10 text-center"
                    data-testid="period-detail-placeholder"
                >
                    <p class="text-sm font-semibold text-stone-900">期間を選んでください</p>
                    <p class="text-xs text-stone-500">左の期間を押すと、倍率とその根拠をここに表示します。</p>
                </div>
                <div v-else-if="periodError" class="flex h-full flex-col items-center justify-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-10 text-center">
                    <p class="text-sm text-stone-600">倍率を表示できませんでした。</p>
                    <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="loadPeriod">再読み込み</button>
                </div>
                <div
                    v-else-if="!periodDetail || periodDetail.data.id !== selectedPeriodId"
                    class="flex h-full min-h-48 items-center justify-center rounded-xl border border-stone-200 bg-white px-6 py-10 text-center text-sm text-stone-500"
                >
                    読み込み中…
                </div>
                <SalesEventPeriodDetail v-else :key="periodDetail.data.id" :detail="periodDetail" :is-loading="isPeriodLoading" @saved="onPeriodSaved" @close="closePeriod" />
            </div>
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeForm">
            <div role="dialog" aria-modal="true" aria-labelledby="event-form-title" class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5">
                <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 id="event-form-title" class="text-base font-bold text-stone-900">{{ editing.event ? "イベントを編集" : "イベントを登録" }}</h2>
                        <p class="text-xs text-stone-500">
                            {{ editing.event ? "期間は、イベントの期間の一覧で追加・変更します。" : `期間は${maxDays}日までです。あとから期間をいくつでも追加できます。` }}
                        </p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeForm"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto px-5 py-5">
                    <SalesEventForm :event="editing.event" :max-days="maxDays" @saved="onSaved" @cancel="closeForm" />
                </div>
            </div>
        </div>
    </div>
</template>
