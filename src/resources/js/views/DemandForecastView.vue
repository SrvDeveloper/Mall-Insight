<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, type RouteLocationRaw } from "vue-router";
import SalesScopeControls from "@/components/actuals/SalesScopeControls.vue";
import ForecastItemChart from "@/components/forecast/ForecastItemChart.vue";
import ForecastPeriodBar from "@/components/forecast/ForecastPeriodBar.vue";
import ForecastTable from "@/components/forecast/ForecastTable.vue";
import { useForecastTimeline } from "@/components/forecast/useForecastTimeline";

/**
 * 需要予測（バックログ B-006・B-131、決定記録 K-035・K-123）。品番別売上と同じ構成で、対象品番の過去の販売実績と、これからの需要予測を並べる。
 * 過去の月・日には、その時点に戻って今の計算方法で予測し直した数を重ね、販売実績との差異を確かめられる（予測精度 B-130 と同じ計算）。
 *
 * - 月ごと（過去12か月・今月・これからの11か月）と日ごと（初期値は過去30日・今日・これからの30日）を切り替える。
 * - 全体・Amazon・BOSS、数量と金額（B-123）、グラフと表、予測し直す起点（その月の初め・3か月前・6か月前）、絞り込みを選べる。選んだものは URL に持たせる。
 * - 差異は、月ごとは30%以上、日ごとは50%以上を強調する。日ごとは欠品した日を差異に数えない。
 * - 品番を押すと、SKU別の需要予測の画面に移る（選んでいるモールなどを引き継ぐ）。
 * - 予測は保存せず、開くたびに最新の販売実績から計算する（K-035）。予測し直した数は、使ったデータが変わらない間はキャッシュから使う。
 */

type ForecastFilter = "all" | "large" | "unpredictable";

const route = useRoute();
const { scope, unit, measure, offset, updateQuery, selectUnit, result, isLoading, hasError, periodError, load, threshold, items: allItems, labels, events } = useForecastTimeline(ref(null));

const filter = computed<ForecastFilter>(() => (route.query.filter === "large" || route.query.filter === "unpredictable" ? route.query.filter : "all"));
const selectedItemNo = computed(() => (typeof route.query.item === "string" ? route.query.item : null));
const view = computed<"graph" | "table">(() => (route.query.view === "table" ? "table" : "graph"));

const keyword = ref("");
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

const FILTERS = computed<{ value: ForecastFilter; label: string }[]>(() => [
    { value: "all", label: "すべて" },
    { value: "large", label: `差異が大きい（${threshold.value * 100}%以上）` },
    { value: "unpredictable", label: "予測不能あり" },
]);

/** 表に出す過去の月・日の数（月ごとは6か月・12か月、日ごとは7日・14日・30日）。 */
const pastOptions = computed(() =>
    unit.value === "day"
        ? [
              { value: 7, label: "過去7日" },
              { value: 14, label: "過去14日" },
              { value: 30, label: "過去30日" },
          ]
        : [
              { value: 6, label: "過去6か月" },
              { value: 12, label: "過去12か月" },
          ],
);
const pastCount = computed(() => {
    const value = Number(route.query.past);
    return pastOptions.value.some((option) => option.value === value) ? value : pastOptions.value[unit.value === "day" ? 1 : 0]!.value;
});

/** 今月の販売実績の最後の日（「10月12日」）。 */
const salesToLabel = computed(() => {
    const last = (result.value?.meta.channels ?? [])
        .map((channel) => channel.sales_data_to)
        .filter((date): date is string => date !== null)
        .sort()
        .at(-1);
    return last ? `${Number(last.slice(5, 7))}月${Number(last.slice(8, 10))}日` : "前日";
});

/** 全体・Amazon・BOSS の対象品番（B-136、K-129・K-130）。未確定のチャネルは売上上位の品番を使っていることを添える。 */
const targetLabel = computed(() => {
    const label = scope.value === "total" ? "全体" : scope.value === "amazon" ? "Amazon" : "BOSS";
    return result.value?.meta.target_selections?.[scope.value]?.uses_candidates ? `${label}の対象品番（未確定のため売上上位の品番）` : `${label}の対象品番`;
});

const description = computed(() => {
    const columns = result.value?.meta.columns ?? [];
    const past = columns.filter((column) => column.kind === "past").length;
    const future = columns.filter((column) => column.kind === "future").length;
    return unit.value === "day"
        ? `${targetLabel.value}の、過去${past || 30}日の販売実績と、これからの${future || 30}日の日ごとの需要予測です。日ごとの予測は基準平均日販を1日分とし、イベントの日は倍率を、季節係数を登録した品番はその月の係数を掛けます。曜日による売れ方の違いは予測に入れていないため、日ごとの差異は月ごとより大きく出ます。`
        : `${targetLabel.value}の、過去12か月の販売実績と、これからの12か月の需要予測です。過去の月には、その月の初めに今の計算方法で予測し直した数を重ね、実績との差異を確かめられます。品番を押すと、SKUごとの予測と計算の根拠を見られます。`;
});

/** 品番の SKU別の需要予測（選んでいるモールなどを引き継ぎ、絞り込み・見せ方・選んだ品番は持っていかない）。 */
const skuForecastLink = (itemNo: string): RouteLocationRaw => ({
    name: "sku-forecasts",
    params: { itemNo },
    query: { ...route.query, item: undefined, filter: undefined, view: undefined, past: undefined },
});

const segmentClass = (isSelected: boolean): string => (isSelected ? "bg-white font-semibold text-stone-900 shadow-sm" : "text-stone-600 hover:text-stone-900");
const darkSegmentClass = (isSelected: boolean): string => (isSelected ? "bg-stone-900 font-semibold text-white" : "text-stone-600 hover:text-stone-900");
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex max-w-[760px] flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 需要予測</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">需要予測</h1>
                <p class="text-[13px] leading-[1.7] text-stone-600" data-testid="description">{{ description }}</p>
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
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="見せ方">
                        <button
                            v-for="option in [
                                { value: 'graph' as const, label: 'グラフ' },
                                { value: 'table' as const, label: '表' },
                            ]"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="segmentClass(view === option.value)"
                            :aria-pressed="view === option.value"
                            :data-testid="`view-${option.value}`"
                            @click="updateQuery({ view: option.value === 'graph' ? undefined : option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div v-if="view === 'table'" class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" :aria-label="unit === 'day' ? '過去の日' : '過去の月'">
                        <button
                            v-for="option in pastOptions"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="segmentClass(pastCount === option.value)"
                            :aria-pressed="pastCount === option.value"
                            :data-testid="`past-${option.value}`"
                            @click="updateQuery({ past: option.value === pastOptions[0]!.value ? undefined : String(option.value) })"
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

            <ForecastPeriodBar :meta="result.meta" :offset="offset" :threshold="threshold" :period-error="periodError" :view="view" @update="updateQuery" />

            <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                {{ allItems.length === 0 ? "対象品番がありません。" : "条件に一致する品番はありません。" }}
            </div>
            <ForecastTable
                v-else-if="view === 'table'"
                :class="isLoading ? 'opacity-60' : ''"
                :items="visibleItems"
                :columns="result.meta.columns"
                :labels="labels"
                :events="events"
                :unit="result.meta.unit"
                :measure="measure"
                :threshold="threshold"
                :past-count="pastCount"
                :item-link="skuForecastLink"
            />
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
                :sales-to-label="salesToLabel"
                @select="updateQuery({ item: $event })"
            >
                <template #actions="{ itemNo }">
                    <RouterLink
                        :to="skuForecastLink(itemNo)"
                        class="ml-auto inline-flex items-center gap-1 rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-medium text-stone-800 hover:bg-stone-50"
                        data-testid="chart-sku-link"
                    >
                        SKU別の需要予測を見る
                        <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m6 3.5 4.5 4.5L6 12.5" />
                        </svg>
                    </RouterLink>
                </template>
            </ForecastItemChart>
        </section>
    </div>
</template>
