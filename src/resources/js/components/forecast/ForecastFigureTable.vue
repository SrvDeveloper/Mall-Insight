<script setup lang="ts">
import { computed } from "vue";
import { MALL_SERIES } from "@/components/actuals/actualRows";
import { formatFigure, type ForecastMeasure, type TimelineFigures, type TimelineLabel } from "@/components/forecast/timelineRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測のグラフの下に出す、月・日ごとの販売実績・予測（過去は予測し直した数）・差異・イベント（B-131、K-123）。画面デザイン案のとおりに描く。
 * 月ごとはグラフの月に列をそろえ、日ごとは列が多いため横にスクロールする。差異が大きい月・日と、日ごとの欠品した日を色で示す。
 */

const props = defineProps<{
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    events: ForecastTimelineResponse["meta"]["events"][];
    figures: TimelineFigures;
    unit: SalesUnit;
    measure: ForecastMeasure;
    threshold: number;
    /** 今月の販売実績の最後の日（「10月12日」） */
    salesToLabel: string;
}>();

const isDay = computed(() => props.unit === "day");
const gridStyle = computed(() =>
    isDay.value
        ? { gridTemplateColumns: `56px repeat(${props.columns.length}, 48px)`, width: "max-content" }
        : { gridTemplateColumns: `56px repeat(${props.columns.length}, minmax(0, 1fr))`, minWidth: "760px" },
);
const colorOf = (channel: "boss" | "amazon"): string => MALL_SERIES.find((mall) => mall.key === channel)!.color;

/** 差異の表示（「+12%」）。 */
const formatGap = (value: number): string => `${value >= 0 ? "+" : "−"}${Math.abs(value * 100).toFixed(0)}%`;

const cells = computed(() =>
    props.columns.map((column, index) => {
        const variance = props.figures.variance[index] ?? null;
        const isStockout = isDay.value && column.kind === "past" && props.figures.stockout[index];
        const isLarge = variance !== null && Math.abs(variance) >= props.threshold;
        const events = props.events[index] ?? [];
        const label = props.labels[index]!;
        return {
            key: column.key,
            head: isDay.value ? `${label.label} ${label.sub}` : label.label,
            headClass: label.weekend ? "text-stone-400" : "text-stone-500",
            actual: column.kind === "future" || (isDay.value && column.kind === "current") ? "" : formatFigure(props.figures.actual[index], props.measure, props.unit),
            forecast: formatFigure(column.kind === "past" ? props.figures.replayed[index] : props.figures.forecast[index], props.measure, props.unit),
            forecastClass: column.kind === "past" ? "text-stone-600" : "font-semibold text-stone-900",
            gap: isStockout ? "欠品" : column.kind === "past" && variance !== null ? formatGap(variance) : "",
            gapClass: isStockout ? "rounded bg-amber-100 text-amber-800" : isLarge ? "rounded bg-amber-50 font-bold text-amber-700" : "text-stone-500",
            event: events.length === 0 ? "" : isDay.value ? "●" : events.length === 1 ? events[0]!.name.slice(0, 4) : `${events.length}件`,
            eventTitle: events.map((event) => event.name).join("・"),
            eventStyle: events.length === 0 ? {} : isDay.value ? { color: colorOf(events[0]!.channels[0] ?? "boss") } : {},
        };
    }),
);
</script>

<template>
    <div class="overflow-x-auto border-t border-stone-200 pt-2">
        <div class="grid gap-y-1 text-center text-[11px] tabular-nums" :style="gridStyle" data-testid="figure-table">
            <span class="sticky left-0 bg-white text-left text-stone-500">{{ isDay ? "日付" : "月" }}</span>
            <span v-for="cell in cells" :key="`head-${cell.key}`" class="whitespace-nowrap" :class="cell.headClass">{{ cell.head }}</span>
            <span class="sticky left-0 bg-white text-left text-stone-500">実績</span>
            <span v-for="cell in cells" :key="`actual-${cell.key}`" class="text-stone-800" data-testid="figure-actual">{{ cell.actual }}</span>
            <span class="sticky left-0 bg-white text-left text-stone-500">予測</span>
            <span v-for="cell in cells" :key="`forecast-${cell.key}`" :class="cell.forecastClass" data-testid="figure-forecast">{{ cell.forecast }}</span>
            <span class="sticky left-0 bg-white text-left text-stone-500">差異</span>
            <span v-for="cell in cells" :key="`variance-${cell.key}`" :class="cell.gapClass" data-testid="figure-variance">{{ cell.gap }}</span>
            <span class="sticky left-0 bg-white text-left text-stone-500">イベント</span>
            <span v-for="cell in cells" :key="`event-${cell.key}`" :title="cell.eventTitle || undefined" class="flex justify-center">
                <span v-if="cell.event && !isDay" class="truncate rounded border border-stone-200 px-1 text-[10px] text-stone-600">{{ cell.event }}</span>
                <span v-else :style="cell.eventStyle">{{ cell.event }}</span>
            </span>
        </div>
        <p class="mt-2 text-[11px] text-stone-400">
            <template v-if="isDay"
                >予測の行は、過去の日は予測し直した数、今日とこれからの日は需要予測です。今日の実績はまだ取り込まれていません。● はイベントの日（青は BOSS、橙は
                Amazon）。表は横にスクロールできます。</template
            >
            <template v-else
                >予測の行は、過去の月は予測し直した数、今月とこれからの月は需要予測です。今月の実績は{{ salesToLabel }}までの分です。イベントはマウスを乗せると正式な名前を出します。</template
            >
            <template v-if="measure === 'amount'">金額は千円単位です。</template>
        </p>
    </div>
</template>
