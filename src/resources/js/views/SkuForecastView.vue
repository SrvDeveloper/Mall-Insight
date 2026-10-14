<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute } from "vue-router";
import ForecastAdjustmentPanel from "@/components/forecast/ForecastAdjustmentPanel.vue";
import ForecastChart from "@/components/forecast/ForecastChart.vue";
import ForecastPeriodBar from "@/components/forecast/ForecastPeriodBar.vue";
import SkuForecastBasis from "@/components/forecast/SkuForecastBasis.vue";
import { forecastChartFormats, formatFigure } from "@/components/forecast/timelineRows";
import { useForecastTimeline } from "@/components/forecast/useForecastTimeline";

/**
 * SKU別の需要予測（バックログ B-131、決定記録 K-123）。需要予測の画面で品番を押すと移る画面。画面デザイン案のとおり、上に品番の合計
 * （予測の合計・モールの割合・差異・SKUの数）、左にSKUの一覧（予測の多い順）、右に選んだSKUのグラフと、予測の根拠（チャネルごとの
 * 基準平均日販の根拠・月ごとの予測の内訳）を出す。予測できないSKUは理由を出す。品番の手動での予測補正（B-109、K-132）もこの画面で入れる。
 * モール・月ごとと日ごと・数量と金額・日ごとの期間は需要予測の画面と同じく URL に持たせ、戻るときも引き継ぐ。選んだSKU（sku）も URL に持たせる。
 */

const route = useRoute();
const itemNo = computed(() => String(route.params.itemNo));
const { scope, unit, measure, updateQuery, selectUnit, result, isLoading, hasError, periodError, load, threshold, items, labels, events } = useForecastTimeline(itemNo);

const item = computed(() => items.value.find((entry) => entry.itemNo === itemNo.value) ?? null);
const formats = computed(() => forecastChartFormats(measure.value, unit.value));
const isDay = computed(() => unit.value === "day");
const unitLabel = computed(() => (measure.value === "amount" ? "千円" : "個"));
const pastCount = computed(() => result.value?.meta.columns.filter((column) => column.kind === "past").length ?? 0);
const futureCount = computed(() => result.value?.meta.columns.filter((column) => column.kind !== "past").length ?? 0);
const span = (count: number): string => (isDay.value ? `${count}日` : `${count}か月`);

const skus = computed(() => [...(item.value?.skus ?? [])].sort((a, b) => b.figures.forecastTotal - a.figures.forecastTotal));
const selectedSku = computed(() => skus.value.find((entry) => String(entry.sku.sku_id) === route.query.sku) ?? skus.value[0] ?? null);
const variantOf = (entry: { sku: { color_no: string | null; size: string | null } }): string => [entry.sku.color_no, entry.sku.size].filter((value) => value !== null && value !== "").join("・");
const wapeText = (value: number | null, digits = 0): string => (value === null ? "—" : `${(value * 100).toFixed(digits)}%`);

/** モールの割合（これからの予測）。 */
const share = computed(() => {
    const figures = item.value?.figures;
    if (!figures) {
        return [];
    }
    const sumOf = (channel: "boss" | "amazon"): number => (figures.channels[channel]?.forecast ?? []).reduce<number>((sum, value) => sum + (value ?? 0), 0);
    const totals = { boss: sumOf("boss"), amazon: sumOf("amazon") };
    const all = totals.boss + totals.amazon;
    return (["boss", "amazon"] as const)
        .filter((channel) => figures.channels[channel])
        .map((channel) => ({ channel, label: channel === "boss" ? "BOSS" : "Amazon", color: channel === "boss" ? "#2a78d6" : "#eb6834", rate: all > 0 ? totals[channel] / all : 0 }));
});

/** 補正を入れられるチャネル（販売実績を取り込んでいて、その品番のSKUを売っているチャネル）。 */
const adjustableChannels = computed(() =>
    (result.value?.meta.channels ?? [])
        .filter((channel) => channel.sales_data_to !== null && (item.value?.skus ?? []).some((entry) => entry.sku.channels[channel.channel] !== undefined))
        .map((channel) => ({ channel: channel.channel, label: channel.label })),
);

/** 需要予測の画面へ戻る（選んでいるモールなどを引き継ぎ、SKUは持っていかない。品番は選んだままにする）。 */
const backLink = computed(() => ({ name: "forecasts", query: { ...route.query, sku: undefined, item: itemNo.value } }));

const darkSegmentClass = (isSelected: boolean): string => (isSelected ? "bg-stone-900 font-semibold text-white" : "text-stone-600 hover:text-stone-900");
const segmentClass = (isSelected: boolean): string => (isSelected ? "bg-white font-semibold text-stone-900 shadow-[0_1px_2px_rgba(0,0,0,0.08)]" : "text-stone-600 hover:text-stone-900");
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">
                    在庫試算 /
                    <RouterLink :to="backLink" class="text-stone-500 underline-offset-4 hover:text-stone-900 hover:underline" data-testid="back-to-forecasts">需要予測</RouterLink>
                    / SKU別の需要予測
                </p>
                <h1 class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[26px] leading-tight font-bold">
                    SKU別の需要予測
                    <span class="font-mono text-xl font-medium" data-testid="item-no">{{ itemNo }}</span>
                    <span v-if="item" class="self-center rounded-full border border-stone-200 bg-white px-2 py-0.5 text-xs font-normal text-stone-600">{{ item.brand }}・{{ item.category }}</span>
                </h1>
            </div>
            <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px]" role="tablist" aria-label="モール">
                <button
                    v-for="option in [
                        { value: 'total', label: '全体' },
                        { value: 'amazon', label: 'Amazon' },
                        { value: 'boss', label: 'BOSS' },
                    ]"
                    :key="option.value"
                    type="button"
                    role="tab"
                    class="h-8 rounded-[7px] px-3 text-[13px]"
                    :class="darkSegmentClass(scope === option.value)"
                    :aria-selected="scope === option.value"
                    :data-testid="`scope-${option.value}`"
                    @click="updateQuery({ scope: option.value === 'total' ? undefined : option.value })"
                >
                    {{ option.label }}
                </button>
            </div>
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">需要予測を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">計算中…</div>

        <div v-else-if="!item" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500" data-testid="not-found">
            この品番の需要予測はありません。<RouterLink :to="backLink" class="font-semibold text-stone-900 underline">需要予測</RouterLink>の画面へ戻ってください。
        </div>

        <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading" :class="isLoading ? 'opacity-60' : ''">
            <div class="flex flex-wrap items-center gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="月ごとと日ごと">
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
                <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="数量と金額">
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
            </div>
            <ForecastPeriodBar :meta="result.meta" :threshold="threshold" :period-error="periodError" @update="updateQuery" />

            <div class="grid grid-cols-2 border-b border-stone-200 xl:grid-cols-4">
                <div class="border-r border-stone-100 px-4 py-3.5">
                    <p class="m-0 text-[11px] text-stone-500">これからの{{ span(futureCount) }}の予測（品番の合計）</p>
                    <p class="m-0 mt-0.5 text-[22px] font-bold tabular-nums" data-testid="sku-stat-forecast">
                        {{ formatFigure(item.figures.forecastTotal, measure, "month") }}<span class="text-xs font-normal text-stone-500">{{ unitLabel }}</span>
                    </p>
                </div>
                <div class="border-r border-stone-100 px-4 py-3.5">
                    <p class="m-0 text-[11px] text-stone-500">モールの割合（予測）</p>
                    <p class="m-0 mt-2 mb-1 flex h-2 gap-0.5 overflow-hidden rounded-full">
                        <span v-for="part in share" :key="part.channel" :style="{ width: `${(part.rate * 100).toFixed(1)}%`, background: part.color }" />
                    </p>
                    <p class="m-0 text-[11px] text-stone-600" data-testid="sku-stat-share">{{ share.map((part) => `${part.label} ${Math.round(part.rate * 100)}%`).join("・") }}</p>
                </div>
                <div class="border-r border-stone-100 px-4 py-3.5">
                    <p class="m-0 text-[11px] text-stone-500">過去{{ span(pastCount) }}の差異（外れ率）</p>
                    <p class="m-0 mt-0.5 text-[22px] font-bold tabular-nums" :class="item.figures.wape !== null && item.figures.wape >= threshold ? 'text-amber-700' : ''" data-testid="sku-stat-wape">
                        {{ wapeText(item.figures.wape, 1) }}
                    </p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="m-0 text-[11px] text-stone-500">SKU</p>
                    <p class="m-0 mt-0.5 text-[22px] font-bold tabular-nums" data-testid="sku-stat-count">
                        {{ item.skuCount }}<span class="text-xs font-normal text-stone-500"> SKU（予測不能 {{ item.unpredictableCount }}）</span>
                    </p>
                </div>
            </div>

            <ForecastAdjustmentPanel :item-no="itemNo" :channels="adjustableChannels" :forecast-from="result.meta.forecast_from" @changed="load" />

            <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)]">
                <ol class="m-0 max-h-[980px] list-none overflow-y-auto border-stone-200 py-2 max-lg:border-b lg:border-r" aria-label="SKU（予測の多い順）">
                    <li v-for="entry in skus" :key="entry.sku.sku_id">
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-[9px] text-left"
                            :class="selectedSku?.sku.sku_id === entry.sku.sku_id ? 'bg-stone-100' : 'hover:bg-stone-50'"
                            :aria-pressed="selectedSku?.sku.sku_id === entry.sku.sku_id"
                            :data-testid="`sku-${entry.sku.sku_code}`"
                            @click="updateQuery({ sku: String(entry.sku.sku_id) })"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate font-mono text-[13px] font-medium text-stone-900">{{ entry.sku.sku_code }}</span>
                                <span class="text-[11px]" :class="entry.sku.unpredictable_reason_label ? 'text-amber-700' : 'text-stone-500'">{{
                                    entry.sku.unpredictable_reason_label ? "予測不能" : variantOf(entry)
                                }}</span>
                            </span>
                            <span class="flex flex-col items-end tabular-nums">
                                <span class="text-[13px] text-stone-900">{{ formatFigure(entry.figures.forecastTotal, measure, "month") }}{{ unitLabel }}</span>
                                <span class="text-[11px]" :class="entry.figures.wape !== null && entry.figures.wape >= threshold ? 'font-semibold text-amber-700' : 'text-stone-500'"
                                    >差異 {{ wapeText(entry.figures.wape) }}</span
                                >
                            </span>
                        </button>
                    </li>
                </ol>

                <div v-if="selectedSku" class="flex min-w-0 flex-col gap-4 p-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <h2 class="m-0 font-mono text-[17px] font-medium" data-testid="sku-code">{{ selectedSku.sku.sku_code }}</h2>
                        <span v-if="variantOf(selectedSku)" class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ variantOf(selectedSku) }}</span>
                        <span class="text-xs text-stone-500"
                            >{{ span(futureCount) }}の予測 <strong class="text-sm text-stone-900 tabular-nums">{{ formatFigure(selectedSku.figures.forecastTotal, measure, "month") }}</strong
                            >{{ unitLabel }}・差異 {{ wapeText(selectedSku.figures.wape) }}</span
                        >
                    </div>
                    <p v-if="selectedSku.sku.unpredictable_reason_label" class="m-0 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="sku-reason">
                        予測できません：{{ selectedSku.sku.unpredictable_reason_label }}
                    </p>

                    <ForecastChart
                        :columns="result.meta.columns"
                        :labels="labels"
                        :figures="selectedSku.figures"
                        :events="events"
                        :unit="result.meta.unit"
                        :threshold="threshold"
                        :format-value="formats.formatValue"
                        :format-axis="formats.formatAxis"
                        :axis-unit="formats.axisUnit"
                        :height="200"
                        :axis-width="48"
                    />

                    <SkuForecastBasis
                        v-if="selectedSku.sku.detail"
                        :detail="selectedSku.sku.detail"
                        :scope="scope"
                        :unit="result.meta.unit"
                        :columns="result.meta.columns"
                        :labels="labels"
                        :events="events"
                        :timeline="selectedSku.sku.channels"
                    />
                </div>
            </div>
        </section>
    </div>
</template>
