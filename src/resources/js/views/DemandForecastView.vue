<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import SalesScopeControls from "@/components/actuals/SalesScopeControls.vue";
import ForecastItemChart from "@/components/forecast/ForecastItemChart.vue";
import { VARIANCE_THRESHOLDS, columnEvents, timelineItems, timelineLabels, type ForecastMeasure, type ForecastScope } from "@/components/forecast/timelineRows";
import { formatDateTime } from "@/components/salesTarget/format";
import type { ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測（バックログ B-006・B-131、決定記録 K-035・K-123）。品番別売上と同じ構成で、対象品番の過去の販売実績と、これからの需要予測を並べる。
 * 過去の月・日には、その時点に戻って今の計算方法で予測し直した数を重ね、販売実績との差異を確かめられる（予測精度 B-130 と同じ計算）。
 *
 * - 月ごと（過去12か月・今月・これからの11か月）と日ごと（初期値は過去30日・今日・これからの30日）を切り替える。
 * - 全体・Amazon・BOSS、数量と金額（B-123）、予測し直す起点（その月の初め・3か月前・6か月前）、絞り込みを選べる。選んだものは URL に持たせる。
 * - 差異は、月ごとは30%以上、日ごとは50%以上を強調する。日ごとは欠品した日を差異に数えない。
 * - 予測は保存せず、開くたびに最新の販売実績から計算する（K-035）。予測し直した数は、使ったデータが変わらない間はキャッシュから使う。
 */

type ForecastFilter = "all" | "large" | "unpredictable";
type Period = { from: string; to: string } | null;

const route = useRoute();
const router = useRouter();

const scope = computed<ForecastScope>(() => (route.query.scope === "amazon" || route.query.scope === "boss" ? route.query.scope : "total"));
const unit = computed<SalesUnit>(() => (route.query.unit === "day" ? "day" : "month"));
const measure = computed<ForecastMeasure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));
const offset = computed<number>(() => (route.query.offset === "3" ? 3 : route.query.offset === "6" ? 6 : 0));
const filter = computed<ForecastFilter>(() => (route.query.filter === "large" || route.query.filter === "unpredictable" ? route.query.filter : "all"));
const period = computed<Period>(() => (unit.value === "day" && typeof route.query.from === "string" && typeof route.query.to === "string" ? { from: route.query.from, to: route.query.to } : null));
const selectedItemNo = computed(() => (typeof route.query.item === "string" ? route.query.item : null));

function updateQuery(changes: Record<string, string | undefined>): void {
    void router.replace({ query: { ...route.query, ...changes } });
}

function selectUnit(next: SalesUnit): void {
    // 期間は月ごとと日ごとで形が違うため、切り替えたら初期値に戻す
    updateQuery({ unit: next === "month" ? undefined : next, from: undefined, to: undefined });
}

const result = shallowRef<ForecastTimelineResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const periodError = ref<string | null>(null);
const requestKey = computed(() => JSON.stringify([unit.value, offset.value, period.value]));

async function load(): Promise<void> {
    const key = requestKey.value;
    isLoading.value = true;
    hasError.value = false;
    periodError.value = null;
    try {
        const response = await fetchForecastTimeline({ unit: unit.value, offset: offset.value, period: period.value });
        if (key === requestKey.value) {
            result.value = response;
        }
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError && (error.errors.from || error.errors.to)) {
            periodError.value = error.errors.to?.[0] ?? error.errors.from?.[0] ?? null;
        } else if (key === requestKey.value) {
            hasError.value = true;
        }
    } finally {
        if (key === requestKey.value) {
            isLoading.value = false;
        }
    }
}

onMounted(load);
watch(requestKey, (current, previous) => {
    // 月ごと・日ごとを切り替えたときは前の結果を消す（列の形が違うため）
    if ((JSON.parse(previous) as unknown[])[0] !== (JSON.parse(current) as unknown[])[0]) {
        result.value = null;
    }
    void load();
});

const threshold = computed(() => VARIANCE_THRESHOLDS[unit.value]);
const keyword = ref("");
const allItems = computed(() => (result.value ? timelineItems(result.value, scope.value, measure.value) : []));
const counts = computed(() => ({
    all: allItems.value.length,
    large: allItems.value.filter((item) => item.figures.wape !== null && item.figures.wape >= threshold.value).length,
    unpredictable: allItems.value.filter((item) => item.unpredictableCount > 0).length,
}));
const visibleItems = computed(() => {
    const word = keyword.value.trim().toLowerCase();
    return allItems.value
        .filter((item) => filter.value === "all" || (filter.value === "large" ? item.figures.wape !== null && item.figures.wape >= threshold.value : item.unpredictableCount > 0))
        .filter((item) => word === "" || item.itemNo.toLowerCase().includes(word) || item.brand.toLowerCase().includes(word))
        .sort((a, b) => b.figures.forecastTotal - a.figures.forecastTotal);
});
const labels = computed(() => (result.value ? timelineLabels(result.value.meta.columns, result.value.meta.unit) : []));
const events = computed(() => (result.value ? columnEvents(result.value, scope.value) : []));

const FILTERS = computed<{ value: ForecastFilter; label: string }[]>(() => [
    { value: "all", label: "すべて" },
    { value: "large", label: `差異が大きい（${threshold.value * 100}%以上）` },
    { value: "unpredictable", label: "予測不能あり" },
]);
const OFFSETS = [
    { value: 0, label: "その月の初め" },
    { value: 3, label: "3か月前" },
    { value: 6, label: "6か月前" },
];

// 日ごとの期間（過去は販売実績のある日まで、これからは90日先まで、K-123）
const today = computed(() => result.value?.meta.forecast_from ?? new Date().toISOString().slice(0, 10));
const maxTo = computed(() => addDays(today.value, (result.value?.meta.max_future_days ?? 90) - 1));
const salesFrom = computed(() => {
    const dates = (result.value?.meta.channels ?? []).map((channel) => channel.sales_data_from).filter((date): date is string => date !== null);
    return dates.length === 0 ? undefined : dates.sort()[0];
});
function addDays(date: string, count: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + count);
    return value.toISOString().slice(0, 10);
}
const columns = computed(() => result.value?.meta.columns ?? []);
const currentPeriod = computed(() => ({ from: columns.value[0]?.key ?? "", to: columns.value.at(-1)?.key ?? "" }));
const presets = computed(() =>
    [
        { key: "d14", label: "前後2週間", from: -14, to: 14 },
        { key: "d30", label: "前後30日", from: -30, to: 30 },
        { key: "p90", label: "過去90日・30日先", from: -90, to: 30 },
        { key: "f90", label: "過去30日・90日先", from: -30, to: 89 },
    ].map((preset) => ({ ...preset, from: addDays(today.value, preset.from), to: addDays(today.value, preset.to) })),
);
function selectPeriod(from: string, to: string): void {
    if (from !== "" && to !== "") {
        updateQuery({ from, to });
    }
}

const monthRangeText = computed(() => {
    const first = columns.value[0]?.key;
    const last = columns.value.at(-1)?.key;
    return first && last ? `${first.replace("-", "/")} 〜 ${last.replace("-", "/")}（過去12か月・これからの12か月）` : "";
});
const salesToText = computed(() => {
    const dates = (result.value?.meta.channels ?? []).map((channel) => channel.sales_data_to).filter((date): date is string => date !== null);
    return dates.length === 0 ? "販売実績はまだありません" : `販売実績 ${dates.sort().at(-1)!.replaceAll("-", "/")} まで`;
});

const segmentClass = (isSelected: boolean): string => (isSelected ? "bg-white font-semibold text-stone-900 shadow-sm" : "text-stone-600 hover:text-stone-900");
const darkSegmentClass = (isSelected: boolean): string => (isSelected ? "bg-stone-900 font-semibold text-white" : "text-stone-600 hover:text-stone-900");
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex max-w-3xl flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 需要予測</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">需要予測</h1>
                <p class="text-[13px] text-stone-600" data-testid="description">
                    対象品番の、過去の販売実績とこれからの需要予測です。過去の{{
                        unit === "day" ? "日" : "月"
                    }}には、その時点に戻って今の計算方法で予測し直した数を重ね、実績との差異を確かめられます。<RouterLink to="/sales-events" class="font-medium text-stone-900 underline"
                        >イベント</RouterLink
                    >の日は倍率で増やし、<RouterLink to="/seasonal-factors" class="font-medium text-stone-900 underline">季節係数</RouterLink>を登録した品番は月ごとに増減させます。<template
                        v-if="unit === 'day'"
                        >曜日による売れ方の違いは予測に入れていないため、日ごとの差異は月ごとより大きく出ます。</template
                    >
                </p>
            </div>
            <SalesScopeControls :scope="scope" :is-loading="isLoading" @select="updateQuery({ scope: $event === 'total' ? undefined : $event })" @reload="load" />
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">需要予測を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">計算中…</div>

        <div v-else-if="!result.meta.selection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900" data-testid="no-selection">
            対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに需要予測が表示されます。
        </div>

        <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="tablist" aria-label="月ごとと日ごと">
                        <button
                            v-for="option in [
                                { value: 'month' as const, label: '月ごと' },
                                { value: 'day' as const, label: '日ごと' },
                            ]"
                            :key="option.value"
                            type="button"
                            role="tab"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="darkSegmentClass(unit === option.value)"
                            :aria-selected="unit === option.value"
                            :data-testid="`unit-${option.value}`"
                            @click="selectUnit(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="数量と金額">
                        <button
                            v-for="option in [
                                { value: 'quantity' as const, label: '数量' },
                                { value: 'amount' as const, label: '金額' },
                            ]"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="segmentClass(measure === option.value)"
                            :aria-pressed="measure === option.value"
                            :data-testid="`measure-${option.value}`"
                            @click="updateQuery({ measure: option.value === 'quantity' ? undefined : option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                        <button
                            v-for="option in FILTERS"
                            :key="option.value"
                            type="button"
                            class="h-8 rounded-full border bg-white px-3 text-[13px]"
                            :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                            :aria-pressed="filter === option.value"
                            :data-testid="`filter-${option.value}`"
                            @click="updateQuery({ filter: option.value === 'all' ? undefined : option.value })"
                        >
                            {{ option.label }}
                            <span class="tabular-nums" :class="option.value !== 'all' && counts[option.value] > 0 ? 'font-semibold text-amber-700' : 'text-stone-500'">{{ counts[option.value] }}</span>
                        </button>
                    </div>
                </div>
                <label class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
                    <svg class="size-3.75 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <circle cx="7" cy="7" r="4.5" />
                        <path d="m10.5 10.5 3 3" />
                    </svg>
                    <span class="sr-only">品番・ブランドで絞り込み</span>
                    <input v-model="keyword" type="search" placeholder="品番・ブランドで絞り込み" class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400" />
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600">
                <div v-if="unit === 'day'" class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="period-picker">
                    <span class="font-medium text-stone-600">期間</span>
                    <div class="inline-flex flex-wrap gap-1" role="group" aria-label="よく使う期間">
                        <button
                            v-for="preset in presets"
                            :key="preset.key"
                            type="button"
                            class="h-8 rounded-full border bg-white px-3 text-[13px]"
                            :class="
                                currentPeriod.from === preset.from && currentPeriod.to === preset.to
                                    ? 'border-stone-900 font-semibold text-stone-900'
                                    : 'border-stone-200 text-stone-600 hover:border-stone-300'
                            "
                            :aria-pressed="currentPeriod.from === preset.from && currentPeriod.to === preset.to"
                            :data-testid="`period-${preset.key}`"
                            @click="selectPeriod(preset.from, preset.to)"
                        >
                            {{ preset.label }}
                        </button>
                    </div>
                    <div class="inline-flex items-center gap-1.5 text-[13px]">
                        <label class="sr-only" for="forecast-from">開始</label>
                        <input
                            id="forecast-from"
                            type="date"
                            :value="currentPeriod.from"
                            :min="salesFrom"
                            :max="maxTo"
                            class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                            data-testid="period-from"
                            @change="selectPeriod(($event.target as HTMLInputElement).value, currentPeriod.to)"
                        />
                        <span class="text-stone-400">〜</span>
                        <label class="sr-only" for="forecast-to">終了</label>
                        <input
                            id="forecast-to"
                            type="date"
                            :value="currentPeriod.to"
                            :min="salesFrom"
                            :max="maxTo"
                            class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                            data-testid="period-to"
                            @change="selectPeriod(currentPeriod.from, ($event.target as HTMLInputElement).value)"
                        />
                    </div>
                    <span v-if="periodError" class="text-xs text-red-700" role="alert" data-testid="period-error">{{ periodError }}</span>
                </div>
                <span v-else class="tabular-nums" data-testid="month-range">期間 {{ monthRangeText }}</span>

                <div class="inline-flex items-center gap-2">
                    <span class="font-medium">差異を測る予測</span>
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="差異を測る予測">
                        <button
                            v-for="option in OFFSETS"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="segmentClass(offset === option.value)"
                            :aria-pressed="offset === option.value"
                            :data-testid="`offset-${option.value}`"
                            @click="updateQuery({ offset: option.value === 0 ? undefined : String(option.value) })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
                <span class="text-stone-500 tabular-nums" data-testid="calculated-at">計算 {{ formatDateTime(result.meta.calculated_at) }}・{{ salesToText }}</span>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#2a78d6]" />BOSS の実績</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#eb6834]" />Amazon の実績</span>
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-2.5 rounded-sm border border-[#2a78d6] bg-[repeating-linear-gradient(135deg,rgba(42,120,214,.65)_0_3px,rgba(42,120,214,.22)_3px_6px)]" />需要予測（斜線）</span
                >
                <span class="inline-flex items-center gap-1.5"><span class="h-0.75 w-3.5 rounded-full bg-stone-900" />予測し直した数（過去）</span>
                <template v-if="unit === 'day'">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-amber-100 ring-1 ring-amber-300" />欠品した日（差異に数えない）</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-stone-100" />土日</span>
                </template>
                <span class="text-stone-400">差異＝（予測し直した数 − 実績）÷ 実績。差異が{{ threshold * 100 }}%以上の{{ unit === "day" ? "日" : "月" }}を強調します</span>
            </div>

            <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                {{ allItems.length === 0 ? "対象品番がありません。" : "条件に一致する品番はありません。" }}
            </div>
            <ForecastItemChart
                v-else
                :class="isLoading ? 'opacity-60' : ''"
                :items="visibleItems"
                :columns="result.meta.columns"
                :labels="labels"
                :events="events"
                :unit="result.meta.unit"
                :measure="measure"
                :threshold="threshold"
                :selected-item-no="selectedItemNo"
                @select="updateQuery({ item: $event })"
            />
        </section>
    </div>
</template>
