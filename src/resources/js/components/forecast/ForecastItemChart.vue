<script setup lang="ts">
import { computed } from "vue";
import { chartFormats } from "@/components/actuals/actualRows";
import ForecastChart from "@/components/forecast/ForecastChart.vue";
import { formatFigure, formatRate, formatVariance, type ForecastMeasure, type TimelineItem, type TimelineLabel } from "@/components/forecast/timelineRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面のグラフ（B-131、K-123）。品番別売上のグラフ（K-079）と同じく、左に品番の一覧（予測の多い順、全体に対する割合と差異（外れ率）付き）、
 * 右に選んだ品番の数字（予測の合計・基準平均日販・外れ率・偏り）とグラフ、グラフの下に月・日ごとの販売実績・予測・差異・イベントを出す。
 * 選んだ品番は呼び出し側が URL に持たせる。
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
}>();
const emit = defineEmits<{ select: [itemNo: string] }>();

const total = computed(() => props.items.reduce((sum, item) => sum + item.figures.forecastTotal, 0));
const selected = computed(() => props.items.find((item) => item.itemNo === props.selectedItemNo) ?? props.items[0] ?? null);
const formats = computed(() => chartFormats(props.measure));
const isDay = computed(() => props.unit === "day");
const futureCount = computed(() => props.columns.filter((column) => column.kind !== "past").length);
const pastCount = computed(() => props.columns.filter((column) => column.kind === "past").length);

const forecastLabel = computed(() => (isDay.value ? `これからの${futureCount.value}日の予測` : `これからの${futureCount.value}か月の予測`));
const pastLabel = computed(() => (isDay.value ? `過去${pastCount.value}日` : `過去${pastCount.value}か月`));
const formatTotal = (value: number): string => (props.measure === "amount" ? `${formatFigure(value, "amount", "month")}千円` : `${formatFigure(value, "quantity", "month")}個`);

/** グラフの下の表の列の幅。月ごとはグラフの月にそろえ、日ごとは横にスクロールする。 */
const gridStyle = computed(() =>
    isDay.value ? { gridTemplateColumns: `64px repeat(${props.columns.length}, 48px)`, width: "max-content" } : { gridTemplateColumns: `64px repeat(${props.columns.length}, minmax(0, 1fr)) 12px` },
);

const cells = computed(() => {
    const figures = selected.value?.figures;
    return props.columns.map((column, index) => {
        const variance = figures?.variance[index] ?? null;
        const isStockout = isDay.value && column.kind === "past" && !!figures?.stockout[index];
        return {
            key: column.key,
            head: isDay.value ? `${props.labels[index]!.label} ${props.labels[index]!.sub}` : props.labels[index]!.label,
            weekend: props.labels[index]!.weekend,
            actual: column.kind === "future" ? "" : formatFigure(figures?.actual[index], props.measure, props.unit),
            forecast: formatFigure(column.kind === "past" ? figures?.replayed[index] : figures?.forecast[index], props.measure, props.unit),
            isForecast: column.kind !== "past",
            variance: isStockout ? "欠品" : column.kind === "past" ? formatVariance(variance) : "",
            tone: isStockout ? "stockout" : variance !== null && Math.abs(variance) >= props.threshold ? "large" : "normal",
            events: props.events[index] ?? [],
        };
    });
});
</script>

<template>
    <div class="grid gap-0 lg:grid-cols-[18rem_1fr]" data-testid="forecast-item-chart">
        <ol class="max-h-[44rem] overflow-y-auto border-stone-200 py-2 max-lg:border-b lg:border-r" aria-label="品番（予測の多い順）">
            <li v-for="(item, index) in items" :key="item.itemNo">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-stone-50"
                    :class="selected?.itemNo === item.itemNo ? 'bg-stone-100 hover:bg-stone-100' : ''"
                    :aria-pressed="selected?.itemNo === item.itemNo"
                    :data-testid="`rank-${item.itemNo}`"
                    @click="emit('select', item.itemNo)"
                >
                    <span class="w-5 shrink-0 text-right text-[11px] text-stone-400 tabular-nums">{{ index + 1 }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate font-mono text-sm font-medium text-stone-900">{{ item.itemNo }}</span>
                            <span
                                v-if="item.unpredictableCount > 0"
                                class="shrink-0 rounded border border-amber-200 bg-amber-50 px-1 text-[10px] font-semibold text-amber-800"
                                data-testid="rank-unpredictable"
                                >予測不能 {{ item.unpredictableCount }} SKU</span
                            >
                        </span>
                        <span class="mt-1 h-1 w-full rounded-full bg-stone-100"
                            ><span class="block h-full rounded-full bg-stone-400" :style="{ width: `${total > 0 ? (item.figures.forecastTotal / total) * 100 : 0}%` }"
                        /></span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end tabular-nums">
                        <span class="text-sm text-stone-900">{{ formatFigure(item.figures.forecastTotal, measure, "month") }}</span>
                        <span
                            class="text-[11px]"
                            :class="item.figures.wape !== null && item.figures.wape >= threshold ? 'font-semibold text-amber-700' : 'text-stone-500'"
                            :data-testid="`rank-wape-${item.itemNo}`"
                            >差異 {{ formatRate(item.figures.wape, 0) }}</span
                        >
                    </span>
                </button>
            </li>
        </ol>

        <div v-if="selected" class="flex min-w-0 flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h3 class="font-mono text-lg font-medium text-stone-900" data-testid="chart-item-no">{{ selected.itemNo }}</h3>
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ selected.brand }}・{{ selected.category }}</span>
                <span class="text-xs text-stone-500 tabular-nums">{{ selected.skuCount }} SKU</span>
                <slot name="actions" :item-no="selected.itemNo" />
            </div>

            <dl class="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex flex-col gap-0.5 rounded-lg border border-stone-200 px-3 py-2.5">
                    <dt class="text-[11px] text-stone-500">{{ forecastLabel }}</dt>
                    <dd class="text-xl font-bold text-stone-900 tabular-nums" data-testid="stat-forecast">{{ formatTotal(selected.figures.forecastTotal) }}</dd>
                    <dd class="text-[11px] text-stone-500 tabular-nums">{{ pastLabel }}の実績 {{ formatTotal(selected.figures.pastActualTotal) }}</dd>
                </div>
                <div class="flex flex-col gap-0.5 rounded-lg border border-stone-200 px-3 py-2.5">
                    <dt class="text-[11px] text-stone-500">基準平均日販</dt>
                    <dd class="text-xl font-bold text-stone-900 tabular-nums" data-testid="stat-base">
                        {{ selected.figures.baseAverage === null ? "—" : selected.figures.baseAverage.toFixed(1) }}<span class="ml-0.5 text-xs font-normal text-stone-500">個/日</span>
                    </dd>
                    <dd class="text-[11px] text-stone-500">直近30日・31〜90日前・91〜180日前の加重平均</dd>
                </div>
                <div
                    class="flex flex-col gap-0.5 rounded-lg border px-3 py-2.5"
                    :class="selected.figures.wape !== null && selected.figures.wape >= threshold ? 'border-amber-300 bg-amber-50' : 'border-stone-200'"
                >
                    <dt class="text-[11px] text-stone-500">{{ pastLabel }}の差異（外れ率）</dt>
                    <dd class="text-xl font-bold text-stone-900 tabular-nums" data-testid="stat-wape">{{ formatRate(selected.figures.wape) }}</dd>
                    <dd class="text-[11px] text-stone-500">{{ isDay ? "日" : "月" }}ごとの差異の大きさの合計 ÷ 実績の合計{{ isDay ? "（欠品した日を除く）" : "" }}</dd>
                </div>
                <div class="flex flex-col gap-0.5 rounded-lg border border-stone-200 px-3 py-2.5">
                    <dt class="text-[11px] text-stone-500">偏り</dt>
                    <dd class="text-xl font-bold text-stone-900 tabular-nums" data-testid="stat-bias">{{ selected.figures.bias === null ? "—" : formatVariance(selected.figures.bias) }}</dd>
                    <dd class="text-[11px] text-stone-500">
                        {{ selected.figures.bias === null ? "比べられる月・日がありません" : selected.figures.bias >= 0 ? "予測が実績より多め" : "予測が実績より少なめ" }}
                    </dd>
                </div>
            </dl>

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

            <div class="overflow-x-auto border-t border-stone-200 pt-2">
                <div class="grid gap-y-1 text-center text-[11px] tabular-nums" :style="gridStyle" data-testid="figure-table">
                    <span class="sticky left-0 bg-white text-left text-stone-500">{{ isDay ? "日付" : "月" }}</span>
                    <span v-for="cell in cells" :key="`head-${cell.key}`" class="whitespace-nowrap" :class="cell.weekend ? 'text-stone-400' : 'text-stone-500'">{{ cell.head }}</span>
                    <span v-if="!isDay" />
                    <span class="sticky left-0 bg-white text-left text-stone-500">実績</span>
                    <span v-for="cell in cells" :key="`actual-${cell.key}`" class="text-stone-800" data-testid="figure-actual">{{ cell.actual }}</span>
                    <span v-if="!isDay" />
                    <span class="sticky left-0 bg-white text-left text-stone-500">予測</span>
                    <span v-for="cell in cells" :key="`forecast-${cell.key}`" :class="cell.isForecast ? 'font-semibold text-stone-900' : 'text-stone-600'" data-testid="figure-forecast">{{
                        cell.forecast
                    }}</span>
                    <span v-if="!isDay" />
                    <span class="sticky left-0 bg-white text-left text-stone-500">差異</span>
                    <span
                        v-for="cell in cells"
                        :key="`variance-${cell.key}`"
                        class="mx-0.5 rounded"
                        :class="cell.tone === 'stockout' ? 'bg-amber-100 text-amber-900' : cell.tone === 'large' ? 'bg-amber-50 font-bold text-amber-800' : 'text-stone-500'"
                        data-testid="figure-variance"
                        >{{ cell.variance }}</span
                    >
                    <span v-if="!isDay" />
                    <span class="sticky left-0 bg-white text-left text-stone-500">イベント</span>
                    <span v-for="cell in cells" :key="`event-${cell.key}`" class="truncate text-[10px] text-stone-600" :title="cell.events.map((event) => event.name).join('・')">
                        <template v-if="cell.events.length > 0">{{ isDay ? "●" : cell.events.length === 1 ? cell.events[0]!.name.slice(0, 4) : `${cell.events.length}件` }}</template>
                    </span>
                    <span v-if="!isDay" />
                </div>
                <p class="mt-2 text-[11px] text-stone-400">
                    予測の行は、過去の{{ isDay ? "日" : "月" }}は予測し直した数、{{ isDay ? "今日" : "今月" }}から先は需要予測です。{{
                        isDay ? "今日の販売実績はまだ取り込まれていません。● はイベントの日（マウスを乗せると名前）。" : "今月の実績は前日までの分です。イベントはマウスを乗せると名前を出します。"
                    }}{{ measure === "amount" ? "金額は千円単位です。" : "" }}
                </p>
            </div>
        </div>
    </div>
</template>
