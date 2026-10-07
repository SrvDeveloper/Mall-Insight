<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchInventoryTrends } from "@/api/inventoryTrends";
import TrendGraph from "@/components/inventoryTrend/TrendGraph.vue";
import TrendTable from "@/components/inventoryTrend/TrendTable.vue";
import TrendTimeline from "@/components/inventoryTrend/TrendTimeline.vue";
import { COVER_COLORS, COVER_LEGEND, buildItemRows, longMonthLabel, type ItemRows } from "@/components/inventoryTrend/trendRows";
import type { InventoryTrendResponse, SkuTrend } from "@/types/api";

/**
 * 12か月在庫推移（バックログ B-008、PG-730）。対象品番のSKUについて、最新の在庫（6区分の合計、K-028）から、需要予測（K-038）を引き、
 * 入荷予定（K-043）を足して、月ごとの月末在庫と足りない数を表示する。判定する月（6か月後）の欠品（K-005）は、
 * 警告の列を設けず、色と絞り込みで示す。過剰在庫は判定せず（K-046）、推奨発注数も出さない（K-045）。
 * 結果は保存せず、開くたびに最新のデータで計算する（原則5）。
 *
 * 見せ方は「表」「タイムライン」「グラフ」をタブで切り替える（K-047）。選んだ見せ方は URL に持たせ、再読み込みしても変わらない。
 * 表示用のデータはデータを読み込んだときに一度だけ作り、切り替えや絞り込みでは作り直さない。
 */

type TrendView = "table" | "timeline" | "graph";
type TrendFilter = "all" | "shortage" | "uncalculated";

const VIEWS: { value: TrendView; label: string }[] = [
    { value: "table", label: "表" },
    { value: "timeline", label: "タイムライン" },
    { value: "graph", label: "グラフ" },
];

const FILTERS: { value: TrendFilter; label: string }[] = [
    { value: "all", label: "すべて" },
    { value: "shortage", label: "欠品警告あり" },
    { value: "uncalculated", label: "計算できない" },
];

/** 在庫基準日からこの日数以上たっていたら、在庫が古いと警告する（K-021）。 */
const STALE_STOCK_DAYS = 4;

const route = useRoute();
const router = useRouter();

const result = shallowRef<InventoryTrendResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const filter = ref<TrendFilter>("all");
const keyword = ref("");

const view = computed<TrendView>(() => (VIEWS.some((option) => option.value === route.query.view) ? (route.query.view as TrendView) : "table"));

function selectView(value: TrendView): void {
    void router.replace({ query: { ...route.query, view: value === "table" ? undefined : value } });
}

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const longDate = (value: string): string => `${value.slice(0, 4)}年${Number(value.slice(5, 7))}月${Number(value.slice(8, 10))}日`;
const shortDate = (value: string): string => `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`;

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchInventoryTrends();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);

/** 推移の12か月（計算できたどのSKUも同じ）。 */
const months = computed(
    () =>
        result.value?.data
            .flatMap((item) => item.skus)
            .find((sku) => sku.months)
            ?.months?.map((month) => ({ month: month.month, days: month.days })) ?? [],
);
const checkMonthIndex = computed(() => months.value.findIndex((month) => month.month === result.value?.meta.check_month));

/** 表示用のデータ。データを読み込んだときに一度だけ作る。 */
const itemRows = computed<ItemRows[]>(() => buildItemRows(result.value?.data ?? [], months.value, checkMonthIndex.value));

const allSkus = computed(() => result.value?.data.flatMap((item) => item.skus) ?? []);
const counts = computed<Record<TrendFilter, number>>(() => ({
    all: allSkus.value.length,
    shortage: allSkus.value.filter((sku) => sku.warning === "shortage").length,
    uncalculated: allSkus.value.filter((sku) => sku.status !== "calculated").length,
}));

function matchesFilter(sku: SkuTrend): boolean {
    switch (filter.value) {
        case "shortage":
            return sku.warning === "shortage";
        case "uncalculated":
            return sku.status !== "calculated";
        default:
            return true;
    }
}

const visibleItems = computed<ItemRows[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    if (filter.value === "all" && word === "") {
        return itemRows.value;
    }
    return itemRows.value
        .map((group) => {
            const itemMatches = word === "" || group.item.item_no.toLowerCase().includes(word);
            return { ...group, rows: group.rows.filter((row) => matchesFilter(row.sku) && (itemMatches || row.sku.sku_code.toLowerCase().includes(word))) };
        })
        .filter((group) => group.rows.length > 0);
});

const stockAgeDays = computed(() => {
    const meta = result.value?.meta;
    if (!meta?.stock_date) {
        return null;
    }
    return Math.round((Date.parse(meta.forecast_from) - Date.parse(meta.stock_date)) / 86_400_000);
});

/** 計算の根拠の帯（在庫基準日・需要予測・判定する月・対象品番）。 */
const facts = computed(() => {
    const meta = result.value?.meta;
    if (!meta) {
        return [];
    }
    const channels = meta.sales_channels.filter((channel) => channel.sales_data_to);
    const isStale = stockAgeDays.value !== null && stockAgeDays.value >= STALE_STOCK_DAYS;
    return [
        {
            key: "stock",
            label: "在庫基準日",
            value: meta.stock_date ? longDate(meta.stock_date) : "未取得",
            note: !meta.stock_date ? "在庫がまだ取得されていません" : isStale ? `${stockAgeDays.value}日前の在庫です。「在庫の取得」を確認してください` : "6区分の合計",
            warn: !meta.stock_date || isStale,
        },
        {
            key: "forecast",
            label: "需要予測",
            value: channels.length > 0 ? channels.map((channel) => channel.label).join("・") : "販売実績なし",
            note: channels.length > 0 ? `販売実績は ${channels.map((channel) => `${channel.label} ${shortDate(channel.sales_data_to!)}`).join("・")} まで` : "販売実績を取り込んでください",
            warn: channels.length === 0,
        },
        { key: "check", label: "判定する月", value: longMonthLabel(meta.check_month), note: `${meta.settings.check_month_offset}か月後`, warn: false },
        {
            key: "selection",
            label: "対象品番",
            value: meta.selection ? `${result.value!.data.length}品番・${allSkus.value.length} SKU` : "未確定",
            note: meta.selection ? `${formatDateTime(meta.selection.confirmed_at)} に確定` : "対象品番の画面で確定してください",
            warn: !meta.selection,
        },
    ];
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 12か月在庫推移</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">12か月在庫推移</h1>
                <p class="text-[13px] text-stone-600">最新の在庫からシステム需要予測（販売目標ではありません）を引き、入荷予定を足した、12か月先までの月末在庫です。</p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="result" class="text-xs text-stone-500 tabular-nums" data-testid="calculated-at">計算日時 {{ formatDateTime(result.meta.calculated_at) }}</span>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-900 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isLoading"
                    @click="load"
                >
                    <svg class="size-4" :class="isLoading ? 'animate-spin' : ''" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                        <path d="M13.5 2.5v3h-3" />
                    </svg>
                    {{ isLoading ? "計算中…" : "再計算" }}
                </button>
            </div>
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">在庫推移を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">計算中…</div>

        <template v-else>
            <section class="grid grid-cols-2 rounded-xl border border-stone-200 bg-white lg:grid-cols-4" aria-label="計算の根拠" data-testid="trend-basis">
                <div
                    v-for="(fact, index) in facts"
                    :key="fact.key"
                    class="flex flex-col gap-1 px-5 py-4"
                    :class="index > 0 ? 'border-stone-200 max-lg:odd:border-t max-lg:even:border-l lg:border-l' : ''"
                    :data-testid="`fact-${fact.key}`"
                >
                    <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                    <span class="text-base font-semibold text-stone-900 tabular-nums">{{ fact.value }}</span>
                    <span class="text-xs" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'" :data-testid="fact.warn ? 'fact-warning' : undefined">{{ fact.note }}</span>
                </div>
            </section>

            <p v-if="result.meta.overdue_inbound_count > 0" class="rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800" data-testid="overdue-inbound">
                入荷予定月を過ぎた入荷予定が{{ result.meta.overdue_inbound_count }}件あり、今月の入荷として数えています。<RouterLink to="/inbound-plans" class="font-semibold underline"
                    >入荷予定</RouterLink
                >で、入荷済みにするか月を直してください。
            </p>

            <div v-if="!result.meta.selection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900">
                対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに在庫推移が表示されます。
            </div>

            <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="見せ方">
                            <button
                                v-for="option in VIEWS"
                                :key="option.value"
                                type="button"
                                role="tab"
                                class="inline-flex h-8 items-center gap-1.5 rounded-[7px] px-3 text-[13px]"
                                :class="view === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                                :aria-selected="view === option.value"
                                :data-testid="`view-${option.value}`"
                                @click="selectView(option.value)"
                            >
                                <svg v-if="option.value === 'table'" class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <rect x="2" y="2.5" width="12" height="11" rx="1.5" />
                                    <path d="M2 6.5h12M6 6.5v7" />
                                </svg>
                                <svg
                                    v-else-if="option.value === 'timeline'"
                                    class="size-3.5"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    aria-hidden="true"
                                >
                                    <path d="M2 4h9M2 8h6M2 12h11" />
                                </svg>
                                <svg v-else class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12 6 7l3 3 5-6" />
                                </svg>
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
                                @click="filter = option.value"
                            >
                                {{ option.label }}
                                <span class="tabular-nums" :class="option.value === 'shortage' ? 'font-semibold text-red-700' : 'text-stone-500'">{{ counts[option.value] }}</span>
                            </button>
                        </div>
                    </div>
                    <label class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
                        <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="m10.5 10.5 3 3" />
                        </svg>
                        <span class="sr-only">品番・SKUで絞り込み</span>
                        <input v-model="keyword" type="search" placeholder="品番・SKUで絞り込み" class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400" />
                    </label>
                </div>

                <!-- 見せ方ごとの色の意味 -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
                    <template v-if="view === 'table'">
                        <span class="inline-flex items-center gap-1.5"><span class="rounded-full bg-red-100 px-2 py-px text-[11px] font-semibold text-red-700">−17</span>足りない数</span>
                        <span class="inline-flex items-center gap-1.5"><span class="rounded-full bg-amber-100 px-2 py-px text-[11px] font-semibold text-amber-800">0</span>在庫0</span>
                        <span class="inline-flex items-center gap-1.5">
                            <svg
                                class="size-3.5 text-stone-400"
                                viewBox="0 0 16 16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M2.5 6.5v6.5h11V6.5" />
                                <path d="M1.5 6.5h13" />
                                <path d="M8 1.5v6M5.5 5 8 7.5 10.5 5" /></svg
                            >入荷予定あり（品番の行）
                        </span>
                    </template>
                    <template v-else-if="view === 'timeline'">
                        <span class="text-stone-500">在庫が持つ月数</span>
                        <span v-for="step in COVER_LEGEND" :key="step.level" class="inline-flex items-center gap-1.5"
                            ><span class="h-2.5 w-4.5 rounded-sm" :class="COVER_COLORS[step.level]" />{{ step.label }}</span
                        >
                    </template>
                    <template v-else>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4.5 rounded-sm border-t-2 border-stone-900 bg-stone-200" />月末在庫</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-4.5 rounded-sm bg-red-300" />足りない量</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 border-l-[1.5px] border-dashed border-stone-500" />判定する月</span>
                        <span class="inline-flex items-center gap-1.5"
                            ><svg class="h-2 w-2.5 text-stone-500" viewBox="0 0 10 8" aria-hidden="true"><path d="M5 0 10 8H0Z" fill="currentColor" /></svg>入荷予定</span
                        >
                    </template>
                    <span v-if="view !== 'graph'" class="inline-flex items-center gap-1.5"><span class="h-3 w-4.5 rounded-sm bg-stone-900" />判定する月</span>
                    <span v-if="view !== 'graph'" class="text-stone-400">{{ view === "table" ? "数字" : "帯" }}にマウスを乗せると内訳が出ます</span>
                </div>

                <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">条件に一致するSKUはありません。</div>
                <TrendTable v-else-if="view === 'table'" :items="visibleItems" :months="months" :check-month-index="checkMonthIndex" :stock-date="result.meta.stock_date" />
                <TrendTimeline v-else-if="view === 'timeline'" :items="visibleItems" :months="months" :check-month-index="checkMonthIndex" :stock-date="result.meta.stock_date" />
                <TrendGraph v-else :items="visibleItems" :months="months" :check-month-index="checkMonthIndex" />
            </section>
        </template>
    </div>
</template>
