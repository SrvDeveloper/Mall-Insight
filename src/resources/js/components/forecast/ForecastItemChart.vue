<script setup lang="ts">
import { computed } from "vue";
import { chartFormats } from "@/components/actuals/actualRows";
import ForecastChart from "@/components/forecast/ForecastChart.vue";
import ForecastFigureTable from "@/components/forecast/ForecastFigureTable.vue";
import { formatFigure, type ForecastMeasure, type TimelineItem, type TimelineLabel } from "@/components/forecast/timelineRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面のグラフ（B-131、K-123）。画面デザイン案のとおり、品番別売上のグラフ（K-079）と同じく左に品番の一覧（予測の多い順、
 * 全体に対する割合と差異（外れ率）付き）、右に選んだ品番の数字（予測の合計・基準平均日販・外れ率・偏り）とグラフ、グラフの下に
 * 月・日ごとの販売実績・予測・差異・イベントを出す。選んだ品番は呼び出し側が URL に持たせる。
 */

const props = defineProps<{
    items: TimelineItem[];
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    events: ForecastTimelineResponse["meta"]["events"][];
    unit: SalesUnit;
    measure: ForecastMeasure;
    threshold: number;
    selectedItemNo: string | null;
    salesToLabel: string;
}>();
const emit = defineEmits<{ select: [itemNo: string] }>();

const total = computed(() => props.items.reduce((sum, item) => sum + item.figures.forecastTotal, 0));
const selected = computed(() => props.items.find((item) => item.itemNo === props.selectedItemNo) ?? props.items[0] ?? null);
const formats = computed(() => chartFormats(props.measure));
const isDay = computed(() => props.unit === "day");
const futureCount = computed(() => props.columns.filter((column) => column.kind !== "past").length);
const pastCount = computed(() => props.columns.filter((column) => column.kind === "past").length);
const unitLabel = computed(() => (props.measure === "amount" ? "千円" : "個"));
const span = (count: number): string => (isDay.value ? `${count}日` : `${count}か月`);
const formatRate = (value: number | null): string => (value === null ? "—" : `${(value * 100).toFixed(1)}%`);
const formatSigned = (value: number | null): string => (value === null ? "—" : `${value >= 0 ? "+" : "−"}${Math.abs(value * 100).toFixed(1)}%`);

const stats = computed(() => {
    const figures = selected.value?.figures;
    if (!figures) {
        return null;
    }
    const large = figures.wape !== null && figures.wape >= props.threshold;
    return {
        forecastLabel: `これからの${span(futureCount.value)}の予測`,
        forecast: formatFigure(figures.forecastTotal, props.measure, "month"),
        actualNote: isDay.value
            ? `過去${span(pastCount.value)}の実績 ${formatFigure(figures.pastActualTotal, props.measure, "month")}${unitLabel.value}`
            : `前の${span(pastCount.value)}の実績 ${formatFigure(figures.pastActualTotal, props.measure, "month")}${unitLabel.value}${figures.pastActualTotal > 0 ? `（前の${span(pastCount.value)}の ${Math.round((figures.forecastTotal / figures.pastActualTotal) * 100)}%）` : ""}`,
        base: figures.baseAverage === null ? "—" : figures.baseAverage.toFixed(1),
        wapeLabel: `過去${span(pastCount.value)}の差異（外れ率）`,
        wape: formatRate(figures.wape),
        wapeNote: isDay.value ? "日ごとの差異の大きさの合計 ÷ 実績の合計（欠品の日を除く）" : "月ごとの差異の大きさの合計 ÷ 実績の合計",
        wapeClass: large ? "border-amber-300 bg-amber-50" : "border-stone-200",
        bias: formatSigned(figures.bias),
        biasNote:
            figures.bias === null
                ? "比べられる月・日がありません"
                : figures.bias >= 0
                  ? "予測が実績より多め（予測し直した数の合計と実績の合計の差）"
                  : "予測が実績より少なめ（予測し直した数の合計と実績の合計の差）",
    };
});
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)]" data-testid="forecast-item-chart">
        <ol class="m-0 max-h-[700px] list-none overflow-y-auto border-stone-200 py-2 max-lg:border-b lg:border-r" aria-label="品番（予測の多い順）">
            <li v-for="(item, index) in items" :key="item.itemNo">
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2 text-left"
                    :class="selected?.itemNo === item.itemNo ? 'bg-stone-100' : 'hover:bg-stone-50'"
                    :aria-pressed="selected?.itemNo === item.itemNo"
                    :data-testid="`rank-${item.itemNo}`"
                    @click="emit('select', item.itemNo)"
                >
                    <span class="w-[18px] shrink-0 text-right text-[11px] text-stone-400 tabular-nums">{{ index + 1 }}</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="flex items-center gap-1.5">
                            <span class="font-mono text-sm font-medium text-stone-900">{{ item.itemNo }}</span>
                            <span
                                v-if="item.unpredictableCount > 0"
                                class="rounded border border-amber-200 bg-amber-50 px-[5px] text-[10px] font-semibold whitespace-nowrap text-amber-800"
                                data-testid="rank-unpredictable"
                                >予測不能 {{ item.unpredictableCount }} SKU</span
                            >
                        </span>
                        <span class="h-1 w-full rounded-full bg-stone-100"
                            ><span class="block h-full rounded-full bg-stone-400" :style="{ width: `${total > 0 ? ((item.figures.forecastTotal / total) * 100).toFixed(1) : 0}%` }"
                        /></span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end tabular-nums">
                        <span class="text-sm text-stone-900">{{ formatFigure(item.figures.forecastTotal, measure, "month") }}</span>
                        <span
                            class="text-[11px]"
                            :class="item.figures.wape !== null && item.figures.wape >= threshold ? 'font-semibold text-amber-700' : 'text-stone-500'"
                            :data-testid="`rank-wape-${item.itemNo}`"
                            >差異 {{ item.figures.wape === null ? "—" : `${(item.figures.wape * 100).toFixed(0)}%` }}</span
                        >
                    </span>
                </button>
            </li>
        </ol>

        <div v-if="selected && stats" class="flex min-w-0 flex-col gap-3.5 p-4">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h2 class="m-0 font-mono text-lg font-medium" data-testid="chart-item-no">{{ selected.itemNo }}</h2>
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ selected.brand }}・{{ selected.category }}</span>
                <span class="text-xs text-stone-500">{{ selected.skuCount }} SKU</span>
                <slot name="actions" :item-no="selected.itemNo" />
            </div>

            <div class="grid grid-cols-2 gap-2.5 xl:grid-cols-4">
                <div class="flex flex-col gap-0.5 rounded-[10px] border border-stone-200 px-3 py-2.5">
                    <span class="text-[11px] text-stone-500">{{ stats.forecastLabel }}</span>
                    <strong class="text-xl tabular-nums" data-testid="stat-forecast"
                        >{{ stats.forecast }}<span class="ml-0.5 text-xs font-normal text-stone-500">{{ unitLabel }}</span></strong
                    >
                    <span class="text-[11px] text-stone-500">{{ stats.actualNote }}</span>
                </div>
                <div class="flex flex-col gap-0.5 rounded-[10px] border border-stone-200 px-3 py-2.5">
                    <span class="text-[11px] text-stone-500">基準平均日販</span>
                    <strong class="text-xl tabular-nums" data-testid="stat-base">{{ stats.base }}<span class="ml-0.5 text-xs font-normal text-stone-500">個/日</span></strong>
                    <span class="text-[11px] text-stone-500">直近30日・31〜90日前・91〜180日前の加重平均</span>
                </div>
                <div class="flex flex-col gap-0.5 rounded-[10px] border px-3 py-2.5" :class="stats.wapeClass">
                    <span class="text-[11px] text-stone-500">{{ stats.wapeLabel }}</span>
                    <strong class="text-xl tabular-nums" data-testid="stat-wape">{{ stats.wape }}</strong>
                    <span class="text-[11px] text-stone-500">{{ stats.wapeNote }}</span>
                </div>
                <div class="flex flex-col gap-0.5 rounded-[10px] border border-stone-200 px-3 py-2.5">
                    <span class="text-[11px] text-stone-500">偏り</span>
                    <strong class="text-xl tabular-nums" data-testid="stat-bias">{{ stats.bias }}</strong>
                    <span class="text-[11px] text-stone-500">{{ stats.biasNote }}</span>
                </div>
            </div>

            <ForecastChart
                :columns="columns"
                :labels="labels"
                :figures="selected.figures"
                :events="events"
                :unit="unit"
                :threshold="threshold"
                :format-value="formats.formatValue"
                :format-axis="formats.formatAxis"
                :axis-unit="formats.axisUnit"
            />

            <ForecastFigureTable
                :columns="columns"
                :labels="labels"
                :events="events"
                :figures="selected.figures"
                :unit="unit"
                :measure="measure"
                :threshold="threshold"
                :sales-to-label="salesToLabel"
            />
        </div>
    </div>
</template>
