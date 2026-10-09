<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useTemplateRef } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchDemandForecasts } from "@/api/demandForecasts";
import ForecastTooltip from "@/components/demandForecast/ForecastTooltip.vue";
import { shortMonthLabel, yearSpans } from "@/components/inventoryTrend/trendRows";
import { formatThousandYen } from "@/components/salesTarget/format";
import { useAnchoredTooltip } from "@/composables/useAnchoredTooltip";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";
import type { DemandForecastResponse, ItemForecast, MonthlyDemand, SkuForecast } from "@/types/api";

/**
 * 需要予測（バックログ B-006、決定記録 K-035）。対象品番のSKUについて、重ならずに遡る3つの期間（直近30日・31〜90日前・91〜180日前、K-036）の平均日販を重みでまとめた
 * 基準平均日販から、12か月分の月別の需要を表示する。予測は保存せず、開くたびに最新の販売実績から計算する。
 * 基準平均日販の欄にマウスを乗せる（またはフォーカスする）と、使った期間・販売数・販売日数・欠品日数・平均日販・重み（計算の根拠）を
 * ツールチップで確認できる。販売数と販売日数は欠品日を除いた数（K-037）。
 * 予測はチャネル（BOSS・Amazon）ごとに出して合計する（K-038）。表示を「合計」「BOSS」「Amazon」で切り替えられる。
 *
 * 表は約4,000マス（100品番なら約1万5千マス）になるため、次のようにして切り替えとスクロールを軽くする。
 * - 表示ごとの数字（整形済みの文字列）をデータを読み込んだときに一度だけ作り、切り替えでは差し替えるだけにする。
 * - 見えている行（と前後の少し）だけを描き、スクロールに合わせて入れ替える（行の高さは固定）。
 * - 列の幅を固定して、ブラウザが全マスから幅を計算し直さなくて済むようにする。
 *
 * 数量と金額（税込の売値の概算、千円、B-123）を切り替える。金額はSKUの単価（チャネルはその単価、合計はチャネルの単価を基準平均日販で
 * 加重平均した単価で、合計の金額はチャネルの金額の合計になる、K-073）を掛けて出す。単価を出せないSKUは金額の合計に入れない。
 * 選んだ方は URL に持たせる。
 *
 * 見た目は在庫推移の画面（K-047）とそろえる（計算の根拠の帯、切り替えのタブ、絞り込みの札、表の罫線と品番の行）。
 */

type ForecastView = "total" | "boss" | "amazon";
/** 数量で見るか、金額（税込の売値の概算、千円、B-123）で見るか。 */
type Measure = "quantity" | "amount";
type ForecastFilter = "all" | "unpredictable" | "partial";

const FILTERS: { value: ForecastFilter; label: string }[] = [
    { value: "all", label: "すべて" },
    { value: "unpredictable", label: "予測不能" },
    { value: "partial", label: "一部予測不能" },
];

/** 表の行の高さ（在庫推移の表とそろえる）。 */
const ROW_HEIGHT = 40;

/** 表示中のチャネル（または合計）での、SKUの予測。 */
interface SkuFigures {
    baseAverage: number | null;
    monthly: MonthlyDemand[] | null;
    reason: string | null;
    isPartial: boolean;
    /** そのチャネルでは売っていない（子ASINの無いSKUの Amazon、K-042）。予測不能とは区別する */
    notSold: boolean;
}

/** 表示用に整形済みのSKUの行。 */
interface SkuRow {
    sku: SkuForecast;
    figures: SkuFigures;
    baseText: string;
    monthTexts: string[];
    yearText: string;
    /** 予測不能（売っていないチャネルを除く） */
    isUnpredictable: boolean;
    /** 金額で見ているが単価を出せない（予測できたSKUだけ） */
    unpriced: boolean;
}

interface ItemRows {
    item: ItemForecast;
    rows: SkuRow[];
    monthTotalTexts: string[];
    yearTotalText: string;
    /** 予測不能のSKUの数（品番の合計に入らない） */
    unpredictableCount: number;
    /** 金額で見ているとき、単価を出せず品番の合計に入らないSKUの数 */
    unpricedCount: number;
}

interface ViewTable {
    items: ItemRows[];
    months: MonthlyDemand[];
    unpredictableCount: number;
    partialCount: number;
}

/** 販売実績の最新日からこの日数以上たっていたら、取り込みを促す。 */
const STALE_SALES_DAYS = 7;

// 大きなデータのため、中身を深く監視しない（読み込むたびに丸ごと差し替える）
const result = shallowRef<DemandForecastResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const filter = ref<ForecastFilter>("all");
const keyword = ref("");
const view = ref<ForecastView>("total");

const tooltipElement = useTemplateRef<HTMLElement>("tooltipElement");
const { tooltip, show: showTooltip, hide: hideTooltip } = useAnchoredTooltip<SkuForecast>(tooltipElement);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const formatDate = (value: string): string => value.replaceAll("-", "/");
const formatAverage = (value: number): string => value.toFixed(2);
const quantityFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });
const formatQuantity = (value: number): string => quantityFormat.format(Math.round(value));

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchDemandForecasts();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);

const viewOptions = computed(() => [
    { value: "total" as ForecastView, label: "合計", disabled: false },
    ...(result.value?.meta.channels ?? []).map((channel) => ({ value: channel.channel as ForecastView, label: channel.label, disabled: channel.sales_data_to === null })),
]);

function figuresOf(sku: SkuForecast, forView: ForecastView = view.value): SkuFigures {
    if (forView === "total") {
        return { baseAverage: sku.base_average, monthly: sku.monthly, reason: sku.unpredictable_reason_label, isPartial: sku.is_partial, notSold: false };
    }
    const channel = sku.channels.find((candidate) => candidate.channel === forView);
    if (!channel) {
        const label = result.value?.meta.channels.find((candidate) => candidate.channel === forView)?.label ?? "";
        return { baseAverage: null, monthly: null, reason: `${label}では販売していません`, isPartial: false, notSold: true };
    }
    return { baseAverage: channel.base_average, monthly: channel.monthly, reason: channel.unpredictable_reason_label, isPartial: false, notSold: false };
}

function buildTable(forView: ForecastView, forMeasure: Measure): ViewTable {
    let months: MonthlyDemand[] = [];
    let unpredictableCount = 0;
    let partialCount = 0;
    const items = (result.value?.data ?? []).map((item): ItemRows => {
        const monthTotals: number[] = [];
        let yearTotal = 0;
        const rows = item.skus.map((sku): SkuRow => {
            const figures = figuresOf(sku, forView);
            const price = sku.unit_prices[forView];
            const unpriced = forMeasure === "amount" && figures.monthly !== null && price === null;
            const format = forMeasure === "amount" ? formatThousandYen : formatQuantity;
            let skuYear = 0;
            const monthTexts = unpriced
                ? []
                : (figures.monthly ?? []).map((month, index) => {
                      const value = forMeasure === "amount" ? month.quantity * price! : month.quantity;
                      skuYear += value;
                      monthTotals[index] = (monthTotals[index] ?? 0) + value;
                      return format(value);
                  });
            yearTotal += skuYear;
            if (figures.monthly && months.length === 0) {
                months = figures.monthly;
            }
            const isUnpredictable = !figures.monthly && !figures.notSold;
            unpredictableCount += isUnpredictable ? 1 : 0;
            partialCount += figures.isPartial ? 1 : 0;
            return {
                sku,
                figures,
                baseText: figures.baseAverage !== null ? formatAverage(figures.baseAverage) : figures.notSold ? "—" : "予測不能",
                monthTexts,
                yearText: format(skuYear),
                isUnpredictable,
                unpriced,
            };
        });
        return {
            item,
            rows,
            monthTotalTexts: monthTotals.map(forMeasure === "amount" ? formatThousandYen : formatQuantity),
            yearTotalText: (forMeasure === "amount" ? formatThousandYen : formatQuantity)(yearTotal),
            unpredictableCount: rows.filter((row) => row.isUnpredictable).length,
            unpricedCount: rows.filter((row) => row.unpriced).length,
        };
    });
    return { items, months, unpredictableCount, partialCount };
}

const route = useRoute();
const router = useRouter();
const measure = computed<Measure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));

function selectMeasure(value: Measure): void {
    void router.replace({ query: { ...route.query, measure: value === "quantity" ? undefined : value } });
}

/** 合計・チャネルごとの表。データを読み込んだとき（と数量・金額を切り替えたとき）に作り、チャネルの切り替えでは作り直さない。 */
const tables = computed<Record<ForecastView, ViewTable>>(() => ({
    total: buildTable("total", measure.value),
    boss: buildTable("boss", measure.value),
    amazon: buildTable("amazon", measure.value),
}));
const table = computed(() => tables.value[view.value]);

/** 予測の12か月（どのSKUも同じ）。予測できたSKUが無いときは空。 */
const months = computed(() => (tables.value.total.months.length > 0 ? tables.value.total.months : table.value.months));

const skuCount = computed(() => table.value.items.reduce((count, item) => count + item.rows.length, 0));
const unpredictableCount = computed(() => table.value.unpredictableCount);
const partialCount = computed(() => table.value.partialCount);

const counts = computed<Record<ForecastFilter, number>>(() => ({ all: skuCount.value, unpredictable: unpredictableCount.value, partial: partialCount.value }));

function matchesFilter(row: SkuRow): boolean {
    switch (filter.value) {
        case "unpredictable":
            return row.isUnpredictable;
        case "partial":
            return row.figures.isPartial;
        default:
            return true;
    }
}

const visibleItems = computed<ItemRows[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    if (filter.value === "all" && word === "") {
        return table.value.items;
    }
    return table.value.items
        .map((group) => {
            const itemMatches = word === "" || group.item.item_no.toLowerCase().includes(word);
            return { ...group, rows: group.rows.filter((row) => matchesFilter(row) && (itemMatches || row.sku.sku_code.toLowerCase().includes(word))) };
        })
        .filter((group) => group.rows.length > 0);
});
const years = computed(() => yearSpans(months.value));

type FlatRow = { kind: "item"; key: string; group: ItemRows } | { kind: "sku"; key: string; row: SkuRow };

/** 品番の行とSKUの行を1列に並べたもの。 */
const flatRows = computed<FlatRow[]>(() =>
    visibleItems.value.flatMap((group): FlatRow[] => [
        { kind: "item", key: `item-${group.item.item_no}`, group },
        ...group.rows.map((row): FlatRow => ({ kind: "sku", key: `sku-${row.sku.sku_id}`, row })),
    ]),
);

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox, hideTooltip);
const { onScroll, renderedRows, topSpacerHeight, bottomSpacerHeight } = useVirtualRows(flatRows, scrollBox, hideTooltip, ROW_HEIGHT);

/** チャネルの販売実績の最新日から、予測の開始日（今日）までの日数。 */
function salesDataAgeDays(salesDataTo: string): number {
    return Math.round((Date.parse(result.value?.meta.forecast_from ?? salesDataTo) - Date.parse(salesDataTo)) / 86_400_000);
}

/** ツールチップに出すチャネル。合計の表示ではすべてのチャネル、チャネルの表示ではそのチャネルだけ。 */
const tooltipChannels = computed(() => (tooltip.content?.channels ?? []).filter((channel) => view.value === "total" || channel.channel === view.value));

const longDate = (value: string): string => `${value.slice(0, 4)}年${Number(value.slice(5, 7))}月${Number(value.slice(8, 10))}日`;

/** 計算の根拠の帯（チャネルごとの販売実績・対象品番）。在庫推移の画面の帯とそろえる。 */
const facts = computed(() => {
    const meta = result.value?.meta;
    if (!meta) {
        return [];
    }
    const channelFacts = meta.channels.map((channel) => {
        const imported = channel.sales_data_from !== null && channel.sales_data_to !== null;
        const ageDays = imported ? salesDataAgeDays(channel.sales_data_to!) : 0;
        return {
            key: `sales-range-${channel.channel}`,
            label: `販売実績（${channel.label}）`,
            value: imported ? `${longDate(channel.sales_data_to!)}まで` : "未取込",
            note: imported ? `${formatDate(channel.sales_data_from!)} 〜 ${formatDate(channel.sales_data_to!)}` : "まだ取り込まれていません（このチャネルの需要は予測に入りません）",
            stale: imported && ageDays >= STALE_SALES_DAYS ? `最新日から${ageDays}日たっています。新しい販売実績を取り込んでください` : null,
            warn: !imported,
        };
    });
    return [
        ...channelFacts,
        {
            key: "selection",
            label: "対象品番",
            value: meta.selection ? `${meta.selection.item_count}品番・${tables.value.total.items.reduce((count, item) => count + item.rows.length, 0)} SKU` : "未確定",
            note: meta.selection ? `${formatDateTime(meta.selection.confirmed_at)} に確定` : "対象品番の画面で確定してください",
            stale: null,
            warn: !meta.selection,
        },
    ];
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 需要予測</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">需要予測</h1>
                <p class="text-[13px] text-stone-600">対象品番のSKUについて、平均日販から12か月分の需要を予測します。季節による増減はまだ反映しません。画面を開くたびに最新の販売実績で計算します。</p>
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
            <p class="text-sm text-stone-600">需要予測を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">計算中…</div>

        <template v-else>
            <section class="grid grid-cols-1 rounded-xl border border-stone-200 bg-white sm:grid-cols-3" aria-label="計算の根拠" data-testid="forecast-basis">
                <div
                    v-for="(fact, index) in facts"
                    :key="fact.key"
                    class="flex flex-col gap-1 px-5 py-4"
                    :class="index > 0 ? 'border-stone-200 max-sm:border-t sm:border-l' : ''"
                    :data-testid="fact.key"
                >
                    <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                    <span class="text-base font-semibold text-stone-900 tabular-nums">{{ fact.value }}</span>
                    <span class="text-xs tabular-nums" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'">{{ fact.note }}</span>
                    <span v-if="fact.stale" class="text-xs font-medium text-amber-700" data-testid="stale-sales">{{ fact.stale }}</span>
                </div>
            </section>

            <div v-if="!result.meta.selection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900">
                対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに需要予測が表示されます。
            </div>

            <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="表示するチャネル">
                            <button
                                v-for="option in viewOptions"
                                :key="option.value"
                                type="button"
                                class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px] disabled:cursor-not-allowed disabled:opacity-40"
                                :class="view === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                                :aria-pressed="view === option.value"
                                :disabled="option.disabled"
                                :title="option.disabled ? '販売実績がまだ取り込まれていません' : undefined"
                                @click="view = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="数量と金額">
                            <button
                                v-for="option in [
                                    { value: 'quantity' as Measure, label: '数量' },
                                    { value: 'amount' as Measure, label: '金額' },
                                ]"
                                :key="option.value"
                                type="button"
                                class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                                :class="measure === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                                :aria-pressed="measure === option.value"
                                :data-testid="`measure-${option.value}`"
                                @click="selectMeasure(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み" data-testid="forecast-filters">
                            <button
                                v-for="option in FILTERS"
                                :key="option.value"
                                type="button"
                                class="h-8 rounded-full border bg-white px-3 text-[13px]"
                                :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                                :aria-pressed="filter === option.value"
                                :data-testid="`filter-${option.value}`"
                                @click="filter = option.value"
                            >
                                {{ option.label }}
                                <span class="tabular-nums" :class="option.value !== 'all' && counts[option.value] > 0 ? 'font-semibold text-amber-700' : 'text-stone-500'">{{
                                    counts[option.value]
                                }}</span>
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

                <!-- 表の見方（在庫推移の画面の凡例とそろえる） -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
                    <span class="inline-flex items-center gap-1.5"
                        ><span class="rounded-full bg-amber-100 px-2 py-px text-[11px] font-semibold text-amber-800">予測不能</span>販売実績や日数が足りない（理由を表示）</span
                    >
                    <span class="inline-flex items-center gap-1.5"><span class="text-[11px] font-medium text-amber-700">一部</span>一部のチャネルだけ予測できなかった</span>
                    <span class="inline-flex items-center gap-1.5"><span class="text-stone-400">—</span>そのチャネルでは販売していない</span>
                    <span class="text-stone-400">基準平均日販にマウスを乗せると内訳が出ます</span>
                    <span v-if="measure === 'amount'" class="text-stone-500" data-testid="amount-note">金額は税込の売値の概算（千円）。単価は単価の画面の単価、無ければ直近12か月の平均単価です</span>
                </div>

                <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                    {{ filter === "all" && keyword.trim() === "" ? "対象品番にSKUがありません。" : "条件に一致するSKUはありません。" }}
                </div>

                <!-- 表は画面の高さに収まる枠の中でスクロールさせ、列見出しが上に残るようにする。横はドラッグでも動かせる（在庫推移の表とそろえる） -->
                <div
                    v-else
                    ref="scrollBox"
                    class="max-h-[calc(100dvh-5rem)] overflow-auto select-none"
                    :class="[isLoading ? 'opacity-60' : '', isDragging ? 'cursor-grabbing' : '']"
                    data-testid="forecast-scroll"
                    @scroll="onScroll"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerEnd"
                    @pointercancel="onPointerEnd"
                    @lostpointercapture="onPointerEnd"
                    @pointerleave="onPointerLeave"
                >
                    <table class="w-full min-w-[80rem] table-fixed border-separate border-spacing-0 text-left text-[13px]">
                        <colgroup>
                            <col class="w-62" />
                            <col class="w-26" />
                            <col v-for="month in months" :key="month.month" class="w-19" />
                            <col class="w-24" />
                        </colgroup>
                        <thead class="[&_th]:sticky [&_th]:z-20 [&_th]:bg-white">
                            <tr class="text-[11px] font-semibold tracking-wider text-stone-400 [&_th]:top-0">
                                <th class="left-0 z-30!" colspan="2"></th>
                                <th
                                    v-for="(year, index) in years"
                                    :key="year.year"
                                    :colspan="year.span"
                                    class="px-3 pt-2 pb-0.5 text-left"
                                    :class="index > 0 ? 'shadow-[inset_1px_0_0_var(--color-stone-200)]' : ''"
                                >
                                    {{ year.year }}
                                </th>
                                <th class="shadow-[inset_1px_0_0_var(--color-stone-200)]"></th>
                            </tr>
                            <tr class="text-xs font-medium text-stone-500 [&_th]:top-[27px] [&_th]:border-b [&_th]:border-stone-300">
                                <th scope="col" class="left-0 z-30! px-4 pt-1 pb-2">品番・SKU</th>
                                <th scope="col" class="px-3 pt-1 pb-2 text-right whitespace-nowrap">基準平均日販</th>
                                <th v-for="(month, index) in months" :key="month.month" scope="col" class="px-1 pt-1 pb-2 text-center">
                                    <span class="inline-flex min-w-14 flex-col items-center px-1.5 py-1 leading-tight tabular-nums">
                                        {{ shortMonthLabel(month.month) }}
                                        <span class="text-[10px] font-normal text-stone-400">{{ index === 0 ? "今月" : "&nbsp;" }}</span>
                                    </span>
                                </th>
                                <th scope="col" class="px-3 pt-1 pb-2 text-right whitespace-nowrap shadow-[inset_1px_0_0_var(--color-stone-200)]">
                                    {{ measure === "amount" ? "12か月計（千円）" : "12か月計" }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- 見えていない行の分の高さ。スクロールバーの長さと位置を、全行を描いたときと同じにする -->
                            <tr v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }">
                                <td :colspan="months.length + 3" class="p-0" />
                            </tr>
                            <template v-for="entry in renderedRows" :key="entry.key">
                                <tr v-if="entry.kind === 'item'" class="h-[40px] bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="item-row">
                                    <th scope="rowgroup" class="sticky left-0 z-10 bg-stone-50 px-4 text-left font-normal">
                                        <div class="flex items-center gap-1.5 overflow-hidden whitespace-nowrap">
                                            <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ entry.group.item.item_no }}</span>
                                            <span class="min-w-0 truncate rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600"
                                                >{{ entry.group.item.brand }}・{{ entry.group.item.category }}</span
                                            >
                                            <span
                                                v-if="entry.group.unpredictableCount > 0"
                                                class="shrink-0 rounded-full bg-amber-100 px-2 py-px text-[11px] font-semibold text-amber-800"
                                                data-testid="item-unpredictable"
                                            >
                                                予測不能 {{ entry.group.unpredictableCount }}/{{ entry.group.rows.length }}
                                            </span>
                                        </div>
                                    </th>
                                    <td class="truncate px-3 text-right text-[11px] text-stone-500">
                                        <span v-if="entry.group.unpredictableCount > 0 || entry.group.unpricedCount > 0" data-testid="item-excluded"
                                            >{{ [entry.group.unpredictableCount > 0 ? "予測不能" : "", entry.group.unpricedCount > 0 ? "単価なし" : ""].filter(Boolean).join("・") }}を除く</span
                                        >
                                    </td>
                                    <td v-for="(month, index) in months" :key="month.month" class="px-3 text-right font-semibold text-stone-900 tabular-nums">
                                        {{ entry.group.monthTotalTexts[index] ?? "0" }}
                                    </td>
                                    <td class="px-3 text-right font-semibold text-stone-900 tabular-nums shadow-[inset_1px_0_0_var(--color-stone-200)]">{{ entry.group.yearTotalText }}</td>
                                </tr>
                                <tr v-else class="group h-[40px] hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="sku-row">
                                    <!-- 横スクロールで下の列が透けないよう、固定した列には不透明な背景を付け、行のハイライトに合わせて色を変える -->
                                    <td class="sticky left-0 z-10 truncate bg-white py-0 pr-4 pl-7 font-mono text-[12.5px] text-stone-700 group-hover:bg-stone-50" :title="entry.row.sku.sku_code">
                                        {{ entry.row.sku.sku_code }}
                                    </td>
                                    <!-- ツールチップは数字だけでなく欄全体にマウスを乗せたときに出す（在庫推移の表とそろえる） -->
                                    <td
                                        tabindex="0"
                                        class="cursor-help px-3 text-right whitespace-nowrap hover:bg-stone-200/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                                        :aria-describedby="tooltip.content?.sku_id === entry.row.sku.sku_id ? 'average-tooltip' : undefined"
                                        data-testid="base-average-td"
                                        @mouseenter="showTooltip($event, entry.row.sku)"
                                        @mouseleave="hideTooltip"
                                        @focus="showTooltip($event, entry.row.sku, 0)"
                                        @blur="hideTooltip"
                                    >
                                        <span
                                            class="tabular-nums"
                                            :class="
                                                entry.row.figures.baseAverage !== null
                                                    ? 'text-stone-700'
                                                    : entry.row.figures.notSold
                                                      ? 'text-stone-300'
                                                      : 'rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800'
                                            "
                                            data-testid="base-average"
                                        >
                                            {{ entry.row.baseText }}
                                        </span>
                                        <span v-if="entry.row.figures.isPartial" class="ml-1 text-[10px] font-medium text-amber-700" data-testid="partial">一部</span>
                                    </td>
                                    <template v-if="entry.row.figures.monthly && !entry.row.unpriced">
                                        <td v-for="(text, index) in entry.row.monthTexts" :key="index" class="px-3 text-right text-stone-700 tabular-nums">{{ text }}</td>
                                        <td class="px-3 text-right text-stone-700 tabular-nums shadow-[inset_1px_0_0_var(--color-stone-200)]">{{ entry.row.yearText }}</td>
                                    </template>
                                    <td v-else-if="entry.row.unpriced" :colspan="months.length + 1" class="truncate px-3" data-testid="unpriced">
                                        <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs text-amber-800">単価なし：登録した単価も販売実績も無いため、金額を出せません</span>
                                    </td>
                                    <td v-else :colspan="months.length + 1" class="truncate px-3" :title="entry.row.figures.reason ?? undefined" data-testid="unpredictable-reason">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs" :class="entry.row.figures.notSold ? 'bg-stone-100 text-stone-500' : 'bg-amber-50 text-amber-800'">{{
                                            entry.row.figures.reason
                                        }}</span>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }">
                                <td :colspan="months.length + 3" class="p-0" />
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div
                v-if="tooltip.content"
                id="average-tooltip"
                ref="tooltipElement"
                role="tooltip"
                class="pointer-events-none fixed z-50 w-72 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg bg-stone-900 text-left text-stone-100 shadow-xl ring-1 ring-black/20"
                :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }"
            >
                <ForecastTooltip :sku="tooltip.content" :channels="tooltipChannels" :base-average="figuresOf(tooltip.content).baseAverage" :min-sales-days="result.meta.min_sales_days" />
            </div>
        </template>
    </div>
</template>
