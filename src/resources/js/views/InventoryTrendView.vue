<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchInventoryTrends } from "@/api/inventoryTrends";
import CheckMonthSetting from "@/components/inventoryTrend/CheckMonthSetting.vue";
import TrendGraph from "@/components/inventoryTrend/TrendGraph.vue";
import TrendTable from "@/components/inventoryTrend/TrendTable.vue";
import TrendTimeline from "@/components/inventoryTrend/TrendTimeline.vue";
import { COVER_COLORS, COVER_LEGEND, buildItemRows, longMonthLabel, type ItemRows, type StockScope } from "@/components/inventoryTrend/trendRows";
import type { DemandBasis, InventoryTrendResponse, SkuTrend, TrendScope } from "@/types/api";

/**
 * 12か月在庫推移（バックログ B-008、PG-730）。対象品番のSKUについて、最新の在庫（6区分の合計、K-028）から、需要予測（K-038）を引き、
 * 入荷予定（K-043）を足して、月ごとの月末在庫と足りない数を表示する。判定する月（6か月後）の欠品（K-005）は、
 * 警告の列を設けず、色と絞り込みで示す。過剰在庫は判定せず（K-046）、推奨発注数も出さない（K-045）。
 * 結果は保存せず、開くたびに最新のデータで計算する（原則5）。
 *
 * 判定する月は画面から変えられ（B-011、K-050）、変えたら計算し直す。
 *
 * 需要は「需要予測」（初期値）と「販売試算」（年間販売目標どおりに売れた場合、B-122）を切り替える。どちらにもとづく推移かを
 * 見出しの説明と根拠の帯に明記する（原則1）。販売試算で目標を割り振れない月（翌年度の目標が未登録など）は、0とせず
 * 「計算できない」とし（K-109）、判定する月を計算できないSKUは「判定できない」で絞り込める。選んだ需要は URL に持たせる。
 *
 * 在庫は「全体」（6区分の合計）と「Amazon」「BOSS」（そのモールの在庫の区分と需要、B-121）を切り替える。モールの推移には
 * 入荷予定とフリー在庫・ECストックを入れず（K-072）、どれだけ不足するかを示す。補充の判断はしない（K-070）。
 * 品番には、今月から判定する月までの足りない数の合計を出す。選んだ在庫は URL に持たせる。
 *
 * 見せ方は「表」「タイムライン」「グラフ」をタブで切り替える（K-047）。選んだ見せ方は URL に持たせ、再読み込みしても変わらない。
 * 表示用のデータはデータを読み込んだときに一度だけ作り、切り替えや絞り込みでは作り直さない。
 */

type TrendView = "table" | "timeline" | "graph";
type TrendFilter = "all" | "shortage" | "undetermined" | "uncalculated";

const BASES: { value: DemandBasis; label: string; demandLabel: string; description: string }[] = [
    {
        value: "forecast",
        label: "需要予測",
        demandLabel: "需要予測",
        description: "最新の在庫からシステム需要予測（販売目標ではありません）を引き、入荷予定を足した、12か月先までの月末在庫です。",
    },
    {
        value: "sales_target",
        label: "販売試算",
        demandLabel: "販売目標",
        description: "最新の在庫から年間販売目標（月別販売比率でSKUへ割り振った数）を引き、入荷予定を足した、目標どおりに売れた場合の12か月先までの月末在庫です。",
    },
];

const VIEWS: { value: TrendView; label: string }[] = [
    { value: "table", label: "表" },
    { value: "timeline", label: "タイムライン" },
    { value: "graph", label: "グラフ" },
];

const SCOPES: { value: TrendScope; label: string }[] = [
    { value: "total", label: "全体" },
    { value: "amazon", label: "Amazon" },
    { value: "boss", label: "BOSS" },
];

const FILTERS: { value: TrendFilter; label: string }[] = [
    { value: "all", label: "すべて" },
    { value: "shortage", label: "欠品警告あり" },
    { value: "undetermined", label: "判定できない" },
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

const basis = computed<DemandBasis>(() => (route.query.basis === "sales_target" ? "sales_target" : "forecast"));
const basisOption = computed(() => BASES.find((option) => option.value === basis.value)!);

function selectBasis(value: DemandBasis): void {
    void router.replace({ query: { ...route.query, basis: value === "forecast" ? undefined : value } });
}

const scope = computed<TrendScope>(() => (route.query.scope === "amazon" || route.query.scope === "boss" ? route.query.scope : "total"));

function selectScope(value: TrendScope): void {
    void router.replace({ query: { ...route.query, scope: value === "total" ? undefined : value } });
}

/** 現在庫のツールチップで、推移に数える在庫の区分。 */
const stockScope = computed<StockScope>(() => ({
    label: result.value?.meta.scope_label ?? "全体",
    isTotal: (result.value?.meta.scope ?? "total") === "total",
    warehouses: result.value?.meta.scope_warehouses.map((warehouse) => warehouse.warehouse) ?? [],
}));

/** モールの推移で、月初在庫に数える在庫の区分（「Amazon自社出荷＋Amazon FBA」）。 */
const scopeWarehouseText = computed(() => result.value?.meta.scope_warehouses.map((warehouse) => warehouse.label).join("＋") ?? "");

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const longDate = (value: string): string => `${value.slice(0, 4)}年${Number(value.slice(5, 7))}月${Number(value.slice(8, 10))}日`;
const shortDate = (value: string): string => `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`;

async function load(): Promise<void> {
    const [requestedBasis, requestedScope] = [basis.value, scope.value];
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchInventoryTrends(requestedBasis, requestedScope);
        // 計算中に需要や在庫を切り替えたときは、古い方の結果を表示しない
        if (requestedBasis === basis.value && requestedScope === scope.value) {
            result.value = response;
        }
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);
watch([basis, scope], () => {
    result.value = null;
    filter.value = "all";
    void load();
});

/** 推移の12か月（今月から）。 */
const months = computed(() => result.value?.meta.months ?? []);
const checkMonthIndex = computed(() => months.value.findIndex((month) => month.month === result.value?.meta.check_month));

/** 表示用のデータ。データを読み込んだときに一度だけ作る。 */
const itemRows = computed<ItemRows[]>(() => buildItemRows(result.value?.data ?? [], months.value, checkMonthIndex.value));

const allSkus = computed(() => result.value?.data.flatMap((item) => item.skus) ?? []);
const counts = computed<Record<TrendFilter, number>>(() => ({
    all: allSkus.value.length,
    shortage: allSkus.value.filter((sku) => sku.warning === "shortage").length,
    undetermined: allSkus.value.filter((sku) => sku.warning === "undetermined").length,
    uncalculated: allSkus.value.filter((sku) => sku.status !== "calculated").length,
}));

function matchesFilter(sku: SkuTrend): boolean {
    switch (filter.value) {
        case "shortage":
            return sku.warning === "shortage";
        case "undetermined":
            return sku.warning === "undetermined";
        case "uncalculated":
            return sku.status !== "calculated";
        default:
            return true;
    }
}

/** 「判定できない」は該当するSKUがあるとき（販売試算で判定する月を計算できないとき）だけ出す。 */
const visibleFilters = computed(() => FILTERS.filter((option) => option.value !== "undetermined" || counts.value.undetermined > 0 || filter.value === "undetermined"));

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

/** 販売試算で、目標を割り振れた対象品番が1つも無い年度（翌年度の目標が未登録など）。 */
const missingTargetYears = computed(() => result.value?.meta.sales_targets?.filter((year) => year.calculated_item_count === 0) ?? []);

/** 根拠の帯の、需要の欄。需要予測は販売実績の期間、販売試算は年度ごとの目標の登録状況。 */
const demandFact = computed(() => {
    const meta = result.value!.meta;
    if (meta.sales_targets) {
        const missing = missingTargetYears.value[0];
        return {
            key: "sales-target",
            label: "販売試算（販売目標）",
            value: meta.sales_targets.map((year) => `${year.fiscal_year}年度 ${year.calculated_item_count}品番`).join("・"),
            note: missing ? `${missing.fiscal_year}年度の目標が無いため、${longMonthLabel(missing.from)}から計算できません` : "年間販売目標×月別販売比率をSKUへ割り振った数",
            warn: missing !== undefined,
        };
    }
    const channels = meta.sales_channels.filter((channel) => channel.sales_data_to);
    return {
        key: "forecast",
        label: "需要予測",
        value: channels.length > 0 ? channels.map((channel) => channel.label).join("・") : "販売実績なし",
        note: channels.length > 0 ? `販売実績は ${channels.map((channel) => `${channel.label} ${shortDate(channel.sales_data_to!)}`).join("・")} まで` : "販売実績を取り込んでください",
        warn: channels.length === 0,
    };
});

/** 計算の根拠の帯（在庫基準日・需要・判定する月・対象品番）。 */
const facts = computed(() => {
    const meta = result.value?.meta;
    if (!meta) {
        return [];
    }
    const isStale = stockAgeDays.value !== null && stockAgeDays.value >= STALE_STOCK_DAYS;
    return [
        {
            key: "stock",
            label: "在庫基準日",
            value: meta.stock_date ? longDate(meta.stock_date) : "未取得",
            note: !meta.stock_date
                ? "在庫がまだ取得されていません"
                : isStale
                  ? `${stockAgeDays.value}日前の在庫です。「在庫の取得」を確認してください`
                  : meta.scope === "total"
                    ? "6区分の合計"
                    : scopeWarehouseText.value,
            warn: !meta.stock_date || isStale,
        },
        demandFact.value,
        // 判定する月は CheckMonthSetting で表示・変更する（B-011）
        { key: "check", label: "判定する月", value: "", note: "", warn: false },
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
                <p class="text-xs text-stone-500">在庫試算 / 在庫推移</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">在庫推移</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="basis-description">{{ basisOption.description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- 地（stone-100）の上に置くため、白い面と枠で浮かせる。高さは再計算のボタン（h-10）にそろえる -->
                <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="在庫推移の需要">
                    <button
                        v-for="option in BASES"
                        :key="option.value"
                        type="button"
                        role="tab"
                        class="inline-flex h-8 items-center gap-1.5 rounded-[7px] px-3 text-[13px]"
                        :class="basis === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        :aria-selected="basis === option.value"
                        :data-testid="`basis-${option.value}`"
                        @click="selectBasis(option.value)"
                    >
                        <svg
                            v-if="option.value === 'forecast'"
                            class="size-3.5"
                            viewBox="0 0 16 16"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M2 12.5 6 8l2.5 2.5L14 4.5" />
                            <path d="M10.5 4.5H14V8" />
                        </svg>
                        <svg v-else class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <circle cx="8" cy="8" r="5.75" />
                            <circle cx="8" cy="8" r="2.75" />
                            <circle cx="8" cy="8" r="0.5" fill="currentColor" />
                        </svg>
                        {{ option.label }}
                    </button>
                </div>
                <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="在庫推移の在庫">
                    <button
                        v-for="option in SCOPES"
                        :key="option.value"
                        type="button"
                        role="tab"
                        class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                        :class="scope === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        :aria-selected="scope === option.value"
                        :data-testid="`scope-${option.value}`"
                        @click="selectScope(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
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
                    <CheckMonthSetting
                        v-if="fact.key === 'check'"
                        :settings="result.meta.settings"
                        :check-month="result.meta.check_month"
                        :current-month="result.meta.forecast_from.slice(0, 7)"
                        @saved="load"
                    />
                    <template v-else>
                        <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                        <span class="text-base font-semibold text-stone-900 tabular-nums">{{ fact.value }}</span>
                        <span class="text-xs" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'" :data-testid="fact.warn ? 'fact-warning' : undefined">{{ fact.note }}</span>
                    </template>
                </div>
            </section>

            <p v-if="result.meta.scope !== 'total'" class="rounded-lg border border-stone-200 bg-white px-4 py-2.5 text-sm text-stone-700" data-testid="scope-note">
                {{ result.meta.scope_label }}の在庫（{{ scopeWarehouseText }}）から{{ result.meta.scope_label }}の{{
                    basisOption.demandLabel
                }}を引いた推移です。入荷予定は社内在庫に入るものとして含めず、フリー在庫・ECストックは在庫の内訳に参考として示します。
            </p>

            <p v-if="result.meta.overdue_inbound_count > 0" class="rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800" data-testid="overdue-inbound">
                入荷予定月を過ぎた入荷予定が{{ result.meta.overdue_inbound_count }}件あり、今月の入荷として数えています。<RouterLink to="/inbound-plans" class="font-semibold underline"
                    >入荷予定</RouterLink
                >で、入荷済みにするか月を直してください。
            </p>

            <p v-for="year in missingTargetYears" :key="year.fiscal_year" class="rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800" data-testid="missing-target-year">
                {{ year.fiscal_year }}年度の販売目標が登録されていないため、{{ longMonthLabel(year.from) }}から後の販売試算は計算できません。<RouterLink
                    :to="`/sales-targets?fy=${year.fiscal_year}`"
                    class="font-semibold underline"
                    >販売目標</RouterLink
                >で登録するか、前年度から引き継いでください。
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
                                v-for="option in visibleFilters"
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
                <TrendTable
                    v-else-if="view === 'table'"
                    :items="visibleItems"
                    :months="months"
                    :check-month-index="checkMonthIndex"
                    :stock-date="result.meta.stock_date"
                    :demand-label="basisOption.demandLabel"
                    :stock-scope="stockScope"
                />
                <TrendTimeline
                    v-else-if="view === 'timeline'"
                    :items="visibleItems"
                    :months="months"
                    :check-month-index="checkMonthIndex"
                    :stock-date="result.meta.stock_date"
                    :demand-label="basisOption.demandLabel"
                    :stock-scope="stockScope"
                />
                <TrendGraph v-else :items="visibleItems" :months="months" :check-month-index="checkMonthIndex" />
            </section>
        </template>
    </div>
</template>
