<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from "vue";
import { RouterLink } from "vue-router";
import { fetchDemandForecasts } from "@/api/demandForecasts";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import type { AverageWindow, DemandForecastResponse, ItemForecast, MonthlyDemand, SkuForecast } from "@/types/api";

/**
 * 需要予測（バックログ B-006、決定記録 K-035）。対象品番のSKUについて、重ならずに遡る3つの期間（直近30日・31〜90日前・91〜180日前、K-036）の平均日販を重みでまとめた
 * 基準平均日販から、12か月分の月別の需要を表示する。予測は保存せず、開くたびに最新の販売実績から計算する。
 * 基準平均日販にマウスを乗せる（またはフォーカスする）と、使った期間・販売数・販売日数・欠品日数・平均日販・重み（計算の根拠）を
 * ツールチップで確認できる。販売数と販売日数は欠品日を除いた数（K-037）。
 * 予測はチャネル（BOSS・Amazon）ごとに出して合計する（K-038）。表示を「合計」「BOSS」「Amazon」で切り替えられる。
 */

type ForecastView = "total" | "boss" | "amazon";

/** 表示中のチャネル（または合計）での、SKUの予測。 */
interface SkuFigures {
    baseAverage: number | null;
    monthly: MonthlyDemand[] | null;
    reason: string | null;
    isPartial: boolean;
}

interface ItemRows {
    item: ItemForecast;
    rows: { sku: SkuForecast; figures: SkuFigures }[];
}

/** 販売実績の最新日からこの日数以上たっていたら、取り込みを促す。 */
const STALE_SALES_DAYS = 7;

const result = ref<DemandForecastResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const onlyUnpredictable = ref(false);
const view = ref<ForecastView>("total");

/** 意図せず通り過ぎただけで出ないよう、少しとどまってから表示する。 */
const TOOLTIP_DELAY_MS = 200;
const TOOLTIP_GAP_PX = 6;
const VIEWPORT_MARGIN_PX = 8;

const tooltipElement = useTemplateRef<HTMLElement>("tooltipElement");
const tooltip = reactive<{ sku: SkuForecast | null; left: number; top: number }>({ sku: null, left: 0, top: 0 });
let tooltipTimer: ReturnType<typeof setTimeout> | null = null;

function clearTooltipTimer(): void {
    if (tooltipTimer !== null) {
        clearTimeout(tooltipTimer);
        tooltipTimer = null;
    }
}

/** 基準平均日販のセルの下に、右端をそろえて出す。画面からはみ出すときは上に出し、左右は画面内に収める。 */
function showTooltip(event: Event, sku: SkuForecast, delay = TOOLTIP_DELAY_MS): void {
    clearTooltipTimer();
    const target = event.currentTarget as HTMLElement;
    tooltipTimer = setTimeout(async () => {
        tooltip.sku = sku;
        const anchor = target.getBoundingClientRect();
        tooltip.left = anchor.right;
        tooltip.top = anchor.bottom + TOOLTIP_GAP_PX;
        await nextTick();
        const size = tooltipElement.value?.getBoundingClientRect();
        if (!size) {
            return;
        }
        tooltip.left = Math.min(Math.max(VIEWPORT_MARGIN_PX, anchor.right - size.width), window.innerWidth - size.width - VIEWPORT_MARGIN_PX);
        if (anchor.bottom + TOOLTIP_GAP_PX + size.height > window.innerHeight - VIEWPORT_MARGIN_PX) {
            tooltip.top = Math.max(VIEWPORT_MARGIN_PX, anchor.top - TOOLTIP_GAP_PX - size.height);
        }
    }, delay);
}

function hideTooltip(): void {
    clearTooltipTimer();
    tooltip.sku = null;
}

onBeforeUnmount(clearTooltipTimer);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const formatDate = (value: string): string => value.replaceAll("-", "/");
const formatAverage = (value: number): string => value.toFixed(2);
const formatQuantity = (value: number): string => Math.round(value).toLocaleString();

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

function figuresOf(sku: SkuForecast): SkuFigures {
    if (view.value === "total") {
        return { baseAverage: sku.base_average, monthly: sku.monthly, reason: sku.unpredictable_reason_label, isPartial: sku.is_partial };
    }
    const channel = sku.channels.find((candidate) => candidate.channel === view.value);
    return { baseAverage: channel?.base_average ?? null, monthly: channel?.monthly ?? null, reason: channel?.unpredictable_reason_label ?? null, isPartial: false };
}

const itemRows = computed<ItemRows[]>(() => (result.value?.data ?? []).map((item) => ({ item, rows: item.skus.map((sku) => ({ sku, figures: figuresOf(sku) })) })));

/** 予測の12か月（どのSKUも同じ）。予測できたSKUが無いときは空。 */
const months = computed(() => itemRows.value.flatMap((item) => item.rows).find((row) => row.figures.monthly)?.figures.monthly ?? []);

const skuCount = computed(() => itemRows.value.reduce((count, item) => count + item.rows.length, 0));
const unpredictableCount = computed(() => itemRows.value.reduce((count, item) => count + item.rows.filter((row) => !row.figures.monthly).length, 0));
const partialCount = computed(() => itemRows.value.reduce((count, item) => count + item.rows.filter((row) => row.figures.isPartial).length, 0));

const visibleItems = computed<ItemRows[]>(() => {
    if (!onlyUnpredictable.value) {
        return itemRows.value;
    }
    return itemRows.value.map((item) => ({ ...item, rows: item.rows.filter((row) => !row.figures.monthly || row.figures.isPartial) })).filter((item) => item.rows.length > 0);
});

/** チャネルの販売実績の最新日から、予測の開始日（今日）までの日数。 */
function salesDataAgeDays(salesDataTo: string): number {
    return Math.round((Date.parse(result.value?.meta.forecast_from ?? salesDataTo) - Date.parse(salesDataTo)) / 86_400_000);
}

function monthLabel(month: string): string {
    return `${month.slice(2, 4)}/${Number(month.slice(5, 7))}`;
}

function yearTotal(monthly: MonthlyDemand[] | null): number {
    return monthly?.reduce((total, month) => total + month.quantity, 0) ?? 0;
}

function itemMonthTotal(item: ItemRows, index: number): number {
    return item.rows.reduce((total, row) => total + (row.figures.monthly?.[index]?.quantity ?? 0), 0);
}

function itemYearTotal(item: ItemRows): number {
    return item.rows.reduce((total, row) => total + yearTotal(row.figures.monthly), 0);
}

function hasUnpredictable(item: ItemRows): boolean {
    return item.rows.some((row) => !row.figures.monthly || row.figures.isPartial);
}

/** ツールチップに出すチャネル。合計の表示ではすべてのチャネル、チャネルの表示ではそのチャネルだけ。 */
const tooltipChannels = computed(() => (tooltip.sku?.channels ?? []).filter((channel) => view.value === "total" || channel.channel === view.value));

function excludedLabel(window: AverageWindow): string {
    const minDays = result.value?.meta.min_sales_days ?? 14;
    return window.sales_days < minDays ? `除外（${minDays}日未満）` : "除外（欠品が半分以上）";
}

function windowRange(window: AverageWindow): string {
    return window.from && window.to ? `${formatDate(window.from)} 〜 ${formatDate(window.to)}` : "—";
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-bold tracking-tight text-stone-900">需要予測</h1>
            <p class="text-sm text-stone-500">対象品番のSKUについて、平均日販から12か月分の需要を予測します。季節による増減はまだ反映しません。画面を開くたびに最新の販売実績で計算します。</p>
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-lg border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">需要予測を表示できませんでした。</p>
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-lg border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">計算中…</div>

        <template v-else>
            <section class="flex flex-col gap-3 rounded-lg border border-stone-200 bg-white px-4 py-3 text-sm sm:flex-row sm:items-start sm:justify-between" data-testid="forecast-basis">
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-stone-700">
                    <dt class="text-stone-500">計算日時</dt>
                    <dd class="tabular-nums">{{ formatDateTime(result.meta.calculated_at) }}</dd>
                    <dt class="text-stone-500">販売実績</dt>
                    <dd class="flex flex-col gap-0.5 tabular-nums">
                        <span v-for="channel in result.meta.channels" :key="channel.channel" :data-testid="`sales-range-${channel.channel}`">
                            <span class="inline-block w-16 text-stone-500">{{ channel.label }}</span>
                            <template v-if="channel.sales_data_from && channel.sales_data_to">
                                {{ formatDate(channel.sales_data_from) }} 〜 {{ formatDate(channel.sales_data_to) }}
                                <span
                                    v-if="salesDataAgeDays(channel.sales_data_to) >= STALE_SALES_DAYS"
                                    class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-semibold text-amber-800"
                                    data-testid="stale-sales"
                                >
                                    最新日から{{ salesDataAgeDays(channel.sales_data_to) }}日たっています。新しい販売実績を取り込んでください
                                </span>
                            </template>
                            <span v-else class="text-amber-800">まだ取り込まれていません（このチャネルの需要は予測に入りません）</span>
                        </span>
                    </dd>
                    <dt class="text-stone-500">対象品番</dt>
                    <dd class="tabular-nums">
                        <template v-if="result.meta.selection">{{ result.meta.selection.item_count }}品番（{{ formatDateTime(result.meta.selection.confirmed_at) }} 確定）</template>
                        <span v-else class="text-amber-800">未確定</span>
                    </dd>
                </dl>
                <button
                    type="button"
                    class="shrink-0 self-start rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isLoading"
                    @click="load"
                >
                    {{ isLoading ? "計算中…" : "再計算" }}
                </button>
            </section>

            <div v-if="!result.meta.selection" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900">
                対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに需要予測が表示されます。
            </div>

            <section v-else class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-col gap-2 border-b border-stone-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex rounded-md border border-stone-300 bg-white p-0.5" role="group" aria-label="表示するチャネル">
                            <button
                                v-for="option in viewOptions"
                                :key="option.value"
                                type="button"
                                class="rounded px-3 py-1 text-sm disabled:cursor-not-allowed disabled:opacity-40"
                                :class="view === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100'"
                                :aria-pressed="view === option.value"
                                :disabled="option.disabled"
                                :title="option.disabled ? '販売実績がまだ取り込まれていません' : undefined"
                                @click="view = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <p class="text-sm text-stone-700 tabular-nums" data-testid="forecast-summary">
                            {{ result.data.length }}品番・{{ skuCount }} SKU
                            <span v-if="unpredictableCount > 0" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">予測不能 {{ unpredictableCount }}件</span>
                            <span v-if="partialCount > 0" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">一部予測不能 {{ partialCount }}件</span>
                        </p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-stone-700">
                        <input v-model="onlyUnpredictable" type="checkbox" class="size-4 rounded border-stone-300 text-stone-900 focus:ring-stone-900" />
                        予測不能を含むSKUだけ表示
                    </label>
                </div>

                <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                    {{ onlyUnpredictable ? "予測不能のSKUはありません。" : "対象品番にSKUがありません。" }}
                </div>

                <!-- 表は画面の高さに収まる枠の中でスクロールさせ、横スクロールバーが常に見え、列見出しが上に残るようにする -->
                <div v-else class="max-h-[calc(100dvh-5rem)] overflow-auto" :class="isLoading ? 'opacity-60' : ''" data-testid="forecast-scroll" @scroll="hideTooltip">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs font-semibold text-stone-500 [&_th]:sticky [&_th]:top-0 [&_th]:z-20 [&_th]:bg-stone-50 [&_th]:shadow-[inset_0_-1px_0_var(--color-stone-200)]">
                            <tr>
                                <th scope="col" class="left-0 z-30! px-3 py-2.5">品番・SKU</th>
                                <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">基準平均日販</th>
                                <th v-for="(month, index) in months" :key="month.month" scope="col" class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums">
                                    {{ monthLabel(month.month) }}
                                    <span v-if="index === 0" class="block text-[11px] font-normal">{{ month.days }}日分</span>
                                </th>
                                <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">12か月計</th>
                            </tr>
                        </thead>
                        <tbody v-for="{ item, rows } in visibleItems" :key="item.item_no" class="border-b border-stone-200">
                            <tr class="bg-stone-100/60" data-testid="item-row">
                                <th scope="rowgroup" class="sticky left-0 z-10 bg-stone-100 px-3 py-2 text-left whitespace-nowrap">
                                    <span class="font-mono font-semibold text-stone-900">{{ item.item_no }}</span>
                                    <span class="ml-2 text-xs font-normal text-stone-500">{{ item.brand }}・{{ item.category }}</span>
                                </th>
                                <td class="px-3 py-2 text-right text-xs text-stone-500">
                                    <span v-if="hasUnpredictable({ item, rows })">予測不能を除く</span>
                                </td>
                                <td v-for="(month, index) in months" :key="month.month" class="px-2 py-2 text-right font-semibold text-stone-900 tabular-nums">
                                    {{ formatQuantity(itemMonthTotal({ item, rows }, index)) }}
                                </td>
                                <td class="px-3 py-2 text-right font-semibold text-stone-900 tabular-nums">{{ formatQuantity(itemYearTotal({ item, rows })) }}</td>
                            </tr>
                            <tr v-for="{ sku, figures } in rows" :key="sku.sku_id" class="group border-t border-stone-100 hover:bg-stone-50" data-testid="sku-row">
                                <!-- 横スクロールで下の列が透けないよう、固定した列には不透明な背景を付け、行のハイライトに合わせて色を変える -->
                                <td class="sticky left-0 z-10 bg-white px-3 py-2 whitespace-nowrap group-hover:bg-stone-50">
                                    <span class="font-mono text-stone-800">{{ sku.sku_code }}</span>
                                    <StatusBadge v-if="sku.status === 'inactive'" class="ml-2" tone="neutral" :label="sku.status_label ?? ''" />
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <span
                                        tabindex="0"
                                        class="cursor-help underline decoration-stone-300 decoration-dotted underline-offset-4 tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                        :class="figures.baseAverage !== null ? 'text-stone-700' : 'font-semibold text-amber-800'"
                                        :aria-describedby="tooltip.sku === sku ? 'average-tooltip' : undefined"
                                        data-testid="base-average"
                                        @mouseenter="showTooltip($event, sku)"
                                        @mouseleave="hideTooltip"
                                        @focus="showTooltip($event, sku, 0)"
                                        @blur="hideTooltip"
                                    >
                                        {{ figures.baseAverage !== null ? formatAverage(figures.baseAverage) : "予測不能" }}
                                    </span>
                                    <span v-if="figures.isPartial" class="ml-1 text-[11px] font-semibold text-amber-700" data-testid="partial">一部</span>
                                </td>
                                <template v-if="figures.monthly">
                                    <td v-for="month in figures.monthly" :key="month.month" class="px-2 py-2 text-right text-stone-700 tabular-nums">{{ formatQuantity(month.quantity) }}</td>
                                    <td class="px-3 py-2 text-right text-stone-700 tabular-nums">{{ formatQuantity(yearTotal(figures.monthly)) }}</td>
                                </template>
                                <td v-else :colspan="months.length + 1" class="px-2 py-2 text-amber-800" data-testid="unpredictable-reason">{{ figures.reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div
                v-if="tooltip.sku"
                id="average-tooltip"
                ref="tooltipElement"
                role="tooltip"
                class="pointer-events-none fixed z-50 w-max max-w-[calc(100vw-1rem)] rounded-md border border-stone-900 bg-stone-800 text-left text-stone-100 shadow-xl ring-1 ring-black/5"
                :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }"
            >
                <p class="border-b border-stone-600 px-3 py-2 text-xs text-stone-300">
                    <span class="font-mono font-semibold text-white">{{ tooltip.sku.sku_code }}</span> の基準平均日販
                    <span class="ml-1 font-semibold text-white tabular-nums">{{ figuresOf(tooltip.sku).baseAverage !== null ? formatAverage(figuresOf(tooltip.sku).baseAverage!) : "予測不能" }}</span>
                </p>
                <section v-for="channel in tooltipChannels" :key="channel.channel" class="border-b border-stone-700 pb-1 last:border-b-0" :data-testid="`tooltip-${channel.channel}`">
                    <p class="px-3 pt-2 text-xs font-semibold text-stone-200">
                        {{ channel.channel_label }}
                        <span class="ml-1 tabular-nums" :class="channel.base_average !== null ? 'text-white' : 'text-amber-300'">{{
                            channel.base_average !== null ? formatAverage(channel.base_average) : "予測不能"
                        }}</span>
                    </p>
                    <table class="text-[13px]">
                        <thead class="text-xs text-stone-400">
                            <tr>
                                <th scope="col" class="px-3 pt-1 pb-1 text-left font-medium">期間</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-left font-medium">範囲</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-right font-medium">販売数</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-right font-medium">販売日数</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-right font-medium">欠品日数</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-right font-medium">平均日販</th>
                                <th scope="col" class="px-3 pt-1 pb-1 text-right font-medium">重み</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="window in channel.windows" :key="window.length" :class="window.is_excluded ? 'text-stone-500' : 'text-stone-100'">
                                <td class="px-3 py-0.5 whitespace-nowrap">{{ window.label }}</td>
                                <td class="px-3 py-0.5 whitespace-nowrap tabular-nums">{{ windowRange(window) }}</td>
                                <td class="px-3 py-0.5 text-right tabular-nums">{{ window.sales_quantity.toLocaleString() }}</td>
                                <td class="px-3 py-0.5 text-right tabular-nums">{{ window.sales_days }}</td>
                                <td class="px-3 py-0.5 text-right tabular-nums" :class="window.stockout_days > 0 ? 'font-semibold text-amber-300' : ''">{{ window.stockout_days }}</td>
                                <td class="px-3 py-0.5 text-right whitespace-nowrap tabular-nums">
                                    <template v-if="window.average !== null">{{ formatAverage(window.average) }}</template>
                                    <template v-else>{{ excludedLabel(window) }}</template>
                                </td>
                                <td class="px-3 py-0.5 text-right tabular-nums">{{ window.weight.toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="channel.unpredictable_reason_label" class="px-3 pt-1 pb-1 text-xs text-amber-300">{{ channel.unpredictable_reason_label }}</p>
                </section>
                <p v-if="tooltip.sku.channels.length === 0" class="px-3 py-2 text-xs text-amber-300">{{ tooltip.sku.unpredictable_reason_label }}</p>
            </div>
        </template>
    </div>
</template>
