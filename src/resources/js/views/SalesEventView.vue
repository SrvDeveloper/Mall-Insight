<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchSalesEvents } from "@/api/salesEvents";
import SalesEventFormDialog from "@/components/salesEvent/SalesEventFormDialog.vue";
import { MALLS, formatMultiplier, isMall, periodLabel, setEventFlash, statusClass, takeEventFlash, type MallValue } from "@/components/salesEvent/eventFormat";
import type { SalesEventListResponse, SalesEventPeriodSummary, SalesEventSummary, SalesEventWithPeriods } from "@/types/api";

/**
 * イベントの一覧（バックログ B-129、決定記録 K-112・K-114）。左メニュー「在庫試算」の需要予測の下に置く。
 * 担当者がイベント（キャンペーン）の名前・モールを登録し、開催する期間をイベントにいくつも登録する。期間の日は平均日販から除き、
 * これからの期間の日は、品番ごとの倍率で需要予測と在庫予測を増やす。倍率は、同じイベントの過去の期間で普段の何倍売れたかから
 * システムが候補を出し、担当者が期間ごとに直せる。
 *
 * - 一覧はモールのタブで分ける（選んだモールは URL に持たせる）。いくつものモールで開くイベントは、そのどのタブにも出す。
 * - イベントごとに、次の期間（無ければ最後に終了した期間）と、そのモールのチャネルの倍率、期間の数を出す。イベント名の順に並べる（K-122）。
 * - イベントを押すと、イベントの画面（期間の一覧）に移る。期間を押すと、期間の画面（品番ごとの倍率）に移る。
 */

const route = useRoute();
const router = useRouter();

const result = shallowRef<SalesEventListResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const message = ref<string | null>(takeEventFlash());

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

onMounted(() => void load());

const events = computed(() => result.value?.data ?? []);
const maxDays = computed(() => result.value?.meta.max_days ?? 31);

const hasMall = (event: SalesEventSummary, mall: MallValue): boolean => event.malls.some((item) => item.value === mall);

/** URL で選んだモール。無ければ、イベントのある最初のモール。 */
const selectedMall = computed<MallValue>(() => {
    if (isMall(route.query.mall)) {
        return route.query.mall;
    }
    return MALLS.find((mall) => events.value.some((event) => hasMall(event, mall.value)))?.value ?? MALLS[0].value;
});
const selectedMallInfo = computed(() => MALLS.find((mall) => mall.value === selectedMall.value)!);
const tabs = computed(() => MALLS.map((mall) => ({ ...mall, count: events.value.filter((event) => hasMall(event, mall.value)).length })));
const visibleEvents = computed(() => events.value.filter((event) => hasMall(event, selectedMall.value)));

function selectMall(mall: MallValue): void {
    void router.replace({ query: { ...route.query, mall } });
}

const eventLink = (event: SalesEventSummary) => ({ name: "sales-event", params: { eventId: String(event.id) }, query: { mall: selectedMall.value } });

/** 一覧の倍率に出す期間（次の期間、無ければ最後に終了した期間）。 */
const shownPeriod = (event: SalesEventSummary): SalesEventPeriodSummary | null => event.next_period ?? event.last_period;
/** 選んでいるモールのチャネルの倍率。 */
const shownMultiplier = (event: SalesEventSummary) => shownPeriod(event)?.multipliers.find((multiplier) => multiplier.channel === selectedMallInfo.value.channel) ?? null;
/** 選んでいるモールのほかに開くモール。 */
const otherMalls = (event: SalesEventSummary) => event.malls.filter((mall) => mall.value !== selectedMall.value);

// イベントの登録のモーダル
const isFormOpen = ref(false);

function openForm(): void {
    message.value = null;
    isFormOpen.value = true;
}

async function refreshAfterEventSave(saved: SalesEventWithPeriods): Promise<void> {
    const refreshed = await fetchSalesEvents();
    if (!refreshed.data.some((event) => event.id === saved.id)) {
        throw new Error("登録したイベントが一覧に表示されていません。");
    }
    result.value = refreshed;
    setEventFlash(`${saved.name}を登録しました。${saved.periods.length === 0 ? "期間を追加してください。" : "需要予測と在庫予測に反映されます。"}`);
    const mall = hasMall(saved, selectedMall.value) ? selectedMall.value : (saved.malls[0]?.value ?? selectedMall.value);
    await router.push({ name: "sales-event", params: { eventId: String(saved.id) }, query: { mall } });
}
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
                @click="openForm"
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
                <div class="inline-flex flex-wrap gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="モール">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        role="tab"
                        class="inline-flex h-8 items-center gap-1.5 rounded-[7px] px-3 text-[13px]"
                        :class="selectedMall === tab.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                        :aria-selected="selectedMall === tab.value"
                        :data-testid="`mall-tab-${tab.value}`"
                        @click="selectMall(tab.value)"
                    >
                        {{ tab.label }}
                        <span class="font-normal tabular-nums" :class="selectedMall === tab.value ? 'text-stone-300' : 'text-stone-500'">{{ result ? tab.count : "" }}</span>
                    </button>
                </div>
                <p class="text-[11px] text-stone-400">イベントを押すと、期間の一覧を開きます</p>
            </div>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">イベントを表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="visibleEvents.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                {{ selectedMallInfo.label }}のイベントはまだありません。
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-semibold text-stone-700 hover:bg-stone-100" @click="openForm">イベントを登録</button>
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-200 border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">名前</th>
                            <th scope="col" class="px-3 py-2.5">次の期間<span class="ml-1 font-normal text-stone-400">（無ければ最後の期間）</span></th>
                            <th scope="col" class="px-3 py-2.5">
                                倍率<span class="ml-1 font-normal text-stone-400">（{{ selectedMallInfo.channel === "boss" ? "BOSS" : "Amazon" }}）</span>
                            </th>
                            <th scope="col" class="px-3 py-2.5">期間の数</th>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">開く</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in visibleEvents"
                            :key="event.id"
                            class="cursor-pointer *:border-t *:border-stone-100 hover:bg-stone-50"
                            data-testid="event-row"
                            @click="router.push(eventLink(event))"
                        >
                            <td class="px-4 py-3 align-top">
                                <RouterLink
                                    :to="eventLink(event)"
                                    class="font-semibold text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    data-testid="event-link"
                                    @click.stop
                                >
                                    {{ event.name }}
                                </RouterLink>
                                <span
                                    v-if="otherMalls(event).length > 0"
                                    class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] whitespace-nowrap text-stone-600"
                                    data-testid="other-malls"
                                    >{{
                                        otherMalls(event)
                                            .map((mall) => mall.label)
                                            .join("・")
                                    }}でも開催</span
                                >
                                <p v-if="event.note" class="mt-0.5 max-w-80 truncate text-[11px] text-stone-500" :title="event.note">{{ event.note }}</p>
                            </td>
                            <td class="px-3 py-3 align-top whitespace-nowrap tabular-nums" data-testid="row-period">
                                <template v-if="shownPeriod(event)">
                                    {{ periodLabel(shownPeriod(event)!.starts_on, shownPeriod(event)!.ends_on) }}
                                    <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="statusClass(shownPeriod(event)!.status)">{{
                                        shownPeriod(event)!.status_label
                                    }}</span>
                                </template>
                                <span v-else class="text-xs text-amber-700">期間がありません</span>
                            </td>
                            <td class="px-3 py-3 align-top text-xs whitespace-nowrap text-stone-600 tabular-nums" data-testid="row-multiplier">
                                <template v-if="shownMultiplier(event)">
                                    <strong class="font-semibold" :class="shownMultiplier(event)!.multiplier === null ? 'text-stone-300' : 'text-stone-900'">{{
                                        formatMultiplier(shownMultiplier(event)!.multiplier)
                                    }}</strong>
                                    <span class="ml-1 text-[11px] text-stone-400">{{
                                        shownMultiplier(event)!.kind === "candidate" ? (shownMultiplier(event)!.basis_count === 0 ? "候補なし" : "候補") : "実績"
                                    }}</span>
                                </template>
                            </td>
                            <td class="px-3 py-3 align-top text-xs whitespace-nowrap text-stone-600 tabular-nums" data-testid="row-counts">
                                {{ event.period_count }}件<span class="ml-1 text-stone-400">（予定 {{ event.upcoming_count }}・終了 {{ event.ended_count }}）</span>
                            </td>
                            <td class="px-3 py-3 text-right align-top text-stone-400">
                                <svg class="ml-auto size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m6 3.5 4.5 4.5L6 12.5" />
                                </svg>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <SalesEventFormDialog v-if="isFormOpen" :event="null" :max-days="maxDays" :default-malls="[selectedMall]" :refresh-after-save="refreshAfterEventSave" @close="isFormOpen = false" />
    </div>
</template>
