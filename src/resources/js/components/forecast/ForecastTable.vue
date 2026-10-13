<script setup lang="ts">
import { computed, ref, useTemplateRef } from "vue";
import { RouterLink, type RouteLocationRaw } from "vue-router";
import { formatFigure, formatRate, formatVariance, type ForecastMeasure, type TimelineFigures, type TimelineItem, type TimelineLabel } from "@/components/forecast/timelineRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, ForecastTimelineSku, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面の表（B-131、K-123）。品番の行（開くとSKUの行）に、過去の月・日は販売実績と、予測し直した数・差異を2段で、
 * 今月（今日）から先は需要予測を並べる。差異が大きい月・日は薄い黄色、日ごとの欠品した日は濃い黄色にする。
 * 月ごとは、過去の月を6か月か12か月から選べる。表は大きくなるため、見えている行だけを描く（品番別売上の表とそろえる）。
 */

const props = defineProps<{
    items: TimelineItem[];
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    events: ForecastTimelineResponse["meta"]["events"][];
    unit: SalesUnit;
    measure: ForecastMeasure;
    threshold: number;
    itemLink?: (itemNo: string) => RouteLocationRaw;
}>();

const ROW_HEIGHT = 44;
const isDay = computed(() => props.unit === "day");

/** 月ごとに出す過去の月の数。 */
const pastMonths = ref<6 | 12>(6);
const firstShown = computed(() => {
    if (isDay.value) {
        return 0;
    }
    const pastCount = props.columns.filter((column) => column.kind === "past").length;
    return Math.max(0, pastCount - pastMonths.value);
});
const shown = computed(() => props.columns.map((column, index) => ({ column, index, label: props.labels[index]!, events: props.events[index] ?? [] })).slice(firstShown.value));
const widthOf = (column: ForecastTimelineColumn): number => (column.kind === "past" ? (isDay.value ? 80 : 96) : isDay.value ? 56 : 68);

const open = ref<Set<string>>(new Set());
function toggle(itemNo: string): void {
    const next = new Set(open.value);
    if (next.has(itemNo)) {
        next.delete(itemNo);
    } else {
        next.add(itemNo);
    }
    open.value = next;
}

type FlatRow = { kind: "item"; key: string; item: TimelineItem } | { kind: "sku"; key: string; sku: ForecastTimelineSku; figures: TimelineFigures };
const flatRows = computed<FlatRow[]>(() =>
    props.items.flatMap((item): FlatRow[] => [
        { kind: "item", key: `item-${item.itemNo}`, item },
        ...(open.value.has(item.itemNo) ? item.skus.map((entry): FlatRow => ({ kind: "sku", key: `sku-${entry.sku.sku_id}`, sku: entry.sku, figures: entry.figures })) : []),
    ]),
);

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox);
const { onScroll, renderedRows, topSpacerHeight, bottomSpacerHeight } = useVirtualRows(flatRows, scrollBox, undefined, ROW_HEIGHT);

/** 1つの欄の表示。 */
function cellOf(figures: TimelineFigures, index: number, column: ForecastTimelineColumn) {
    if (column.kind === "past") {
        const variance = figures.variance[index] ?? null;
        const isStockout = isDay.value && figures.stockout[index];
        const replayed = figures.replayed[index];
        return {
            main: formatFigure(figures.actual[index], props.measure, props.unit),
            sub: isStockout
                ? "欠品（数えない）"
                : replayed === null || replayed === undefined
                  ? "予測できません"
                  : `${formatFigure(replayed, props.measure, props.unit)}（${formatVariance(variance) || "—"}）`,
            tone: isStockout ? "stockout" : variance !== null && Math.abs(variance) >= props.threshold ? "large" : "past",
        };
    }
    return {
        main: formatFigure(figures.forecast[index], props.measure, props.unit),
        sub: column.kind === "current" && !isDay.value ? `実績 ${formatFigure(figures.actual[index], props.measure, props.unit)}` : "",
        tone: "future",
    };
}

const cellClass = (tone: string): string => (tone === "stockout" ? "bg-amber-100" : tone === "large" ? "bg-amber-50" : tone === "past" ? "bg-stone-50/70" : "font-semibold text-stone-900");
const subClass = (tone: string): string => (tone === "stockout" ? "text-amber-900" : tone === "large" ? "font-semibold text-amber-800" : "text-stone-500");
const wapeClass = (figures: TimelineFigures): string => (figures.wape !== null && figures.wape >= props.threshold ? "font-bold text-amber-700" : "text-stone-600");
const variantOf = (sku: ForecastTimelineSku): string => [sku.color_no, sku.size].filter((value) => value !== null && value !== "").join("・");
const totalWidth = computed(() => 232 + 76 + 64 + 84 + shown.value.reduce((sum, entry) => sum + widthOf(entry.column), 0));
</script>

<template>
    <div class="flex flex-col">
        <div v-if="!isDay" class="flex flex-wrap items-center gap-2 border-b border-stone-200 px-4 py-2 text-xs text-stone-600">
            過去の月
            <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="過去の月">
                <button
                    v-for="option in [6, 12] as const"
                    :key="option"
                    type="button"
                    class="inline-flex h-7 items-center rounded-[7px] px-2.5 text-xs"
                    :class="pastMonths === option ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                    :aria-pressed="pastMonths === option"
                    :data-testid="`past-${option}`"
                    @click="pastMonths = option"
                >
                    {{ option }}か月
                </button>
            </div>
            <span class="text-stone-400">品番の行の ▸ を押すと、SKUの行を開きます</span>
        </div>

        <div
            ref="scrollBox"
            class="max-h-[calc(100dvh-5rem)] overflow-auto select-none"
            :class="isDragging ? 'cursor-grabbing' : ''"
            data-testid="forecast-table"
            @scroll="onScroll"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerEnd"
            @pointercancel="onPointerEnd"
            @lostpointercapture="onPointerEnd"
            @pointerleave="onPointerLeave"
        >
            <table class="w-full table-fixed border-separate border-spacing-0 text-left text-sm" :style="{ minWidth: `${totalWidth}px` }">
                <colgroup>
                    <col class="w-58" />
                    <col class="w-19" />
                    <col class="w-16" />
                    <col v-for="entry in shown" :key="entry.column.key" :style="{ width: `${widthOf(entry.column)}px` }" />
                    <col class="w-21" />
                </colgroup>
                <thead class="[&_th]:sticky [&_th]:top-0 [&_th]:z-20 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                    <tr class="text-xs font-medium text-stone-500">
                        <th scope="col" class="left-0 z-30! px-4 pt-1 pb-2">品番・SKU</th>
                        <th scope="col" class="px-2 pt-1 pb-2 text-right leading-tight">基準平均日販<span class="block text-[10px] font-normal text-stone-400">個/日</span></th>
                        <th scope="col" class="px-2 pt-1 pb-2 text-right leading-tight">差異<span class="block text-[10px] font-normal text-stone-400">外れ率</span></th>
                        <th
                            v-for="entry in shown"
                            :key="entry.column.key"
                            scope="col"
                            class="px-1 pt-1 pb-2 text-right leading-tight"
                            :class="[
                                entry.column.kind === 'past' ? 'bg-stone-50!' : '',
                                entry.column.kind === 'current' ? 'border-l border-dashed border-l-stone-400' : '',
                                entry.label.weekend ? 'text-stone-400' : '',
                            ]"
                            :title="entry.events.map((event) => event.name).join('・') || undefined"
                        >
                            <span class="px-1 tabular-nums">{{ entry.label.label }}</span>
                            <span class="block px-1 text-[10px] font-normal text-stone-400"
                                >{{ isDay ? entry.label.sub : entry.column.kind === "past" ? "実績 / 予測" : entry.column.kind === "current" ? "今月" : "予測"
                                }}<template v-if="entry.events.length > 0"> ・●</template></span
                            >
                        </th>
                        <th scope="col" class="px-2 pt-1 pb-2 text-right leading-tight">予測の合計<span class="block text-[10px] font-normal text-stone-400">今月（今日）から</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }">
                        <td :colspan="shown.length + 4" class="p-0" />
                    </tr>
                    <template v-for="entry in renderedRows" :key="entry.key">
                        <tr v-if="entry.kind === 'item'" class="h-[44px] bg-white *:border-t *:border-stone-200" data-testid="table-item-row">
                            <th scope="rowgroup" class="sticky left-0 z-10 bg-white px-2 text-left font-normal">
                                <div class="flex items-center gap-1 overflow-hidden whitespace-nowrap">
                                    <button
                                        type="button"
                                        class="flex size-6 shrink-0 items-center justify-center rounded text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                                        :aria-expanded="open.has(entry.item.itemNo)"
                                        :aria-label="`${entry.item.itemNo} のSKUを${open.has(entry.item.itemNo) ? '閉じる' : '開く'}`"
                                        :data-testid="`toggle-${entry.item.itemNo}`"
                                        @click="toggle(entry.item.itemNo)"
                                    >
                                        <svg
                                            class="size-3.5 transition-transform"
                                            :class="open.has(entry.item.itemNo) ? 'rotate-90' : ''"
                                            viewBox="0 0 16 16"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.6"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="m6 3.5 4.5 4.5L6 12.5" />
                                        </svg>
                                    </button>
                                    <span class="flex min-w-0 flex-col leading-tight">
                                        <RouterLink
                                            v-if="itemLink"
                                            :to="itemLink(entry.item.itemNo)"
                                            class="font-mono text-sm font-medium text-stone-900 underline-offset-4 hover:underline"
                                            data-testid="table-item-link"
                                            >{{ entry.item.itemNo }}</RouterLink
                                        >
                                        <span v-else class="font-mono text-sm font-medium text-stone-900">{{ entry.item.itemNo }}</span>
                                        <span class="truncate text-[11px] text-stone-500"
                                            >{{ entry.item.brand }}・{{ entry.item.category
                                            }}<template v-if="entry.item.unpredictableCount > 0"
                                                >・<span class="text-amber-700">予測不能 {{ entry.item.unpredictableCount }} SKU</span></template
                                            ></span
                                        >
                                    </span>
                                </div>
                            </th>
                            <td class="px-2 text-right text-stone-600 tabular-nums">{{ entry.item.figures.baseAverage === null ? "—" : entry.item.figures.baseAverage.toFixed(1) }}</td>
                            <td class="px-2 text-right tabular-nums" :class="wapeClass(entry.item.figures)" data-testid="table-wape">{{ formatRate(entry.item.figures.wape, 0) }}</td>
                            <td
                                v-for="column in shown"
                                :key="column.column.key"
                                class="px-1.5 text-right leading-tight tabular-nums"
                                :class="[cellClass(cellOf(entry.item.figures, column.index, column.column).tone), column.column.kind === 'current' ? 'border-l border-dashed border-l-stone-400' : '']"
                                data-testid="table-cell"
                            >
                                <span class="block">{{ cellOf(entry.item.figures, column.index, column.column).main }}</span>
                                <span class="block text-[10px]" :class="subClass(cellOf(entry.item.figures, column.index, column.column).tone)">{{
                                    cellOf(entry.item.figures, column.index, column.column).sub
                                }}</span>
                            </td>
                            <td class="px-2 text-right font-semibold text-stone-900 tabular-nums" data-testid="table-total">{{ formatFigure(entry.item.figures.forecastTotal, measure, "month") }}</td>
                        </tr>
                        <tr v-else class="h-[44px] *:border-t *:border-stone-100" data-testid="table-sku-row">
                            <th scope="row" class="sticky left-0 z-10 bg-white py-0 pr-3 pl-9 text-left font-normal">
                                <span class="flex flex-col leading-tight">
                                    <span class="truncate font-mono text-xs text-stone-700" :title="entry.sku.sku_code">{{ entry.sku.sku_code }}</span>
                                    <span class="truncate text-[10px]" :class="entry.sku.unpredictable_reason_label ? 'text-amber-700' : 'text-stone-500'">{{
                                        entry.sku.unpredictable_reason_label ? `予測不能：${entry.sku.unpredictable_reason_label}` : variantOf(entry.sku)
                                    }}</span>
                                </span>
                            </th>
                            <td class="px-2 text-right text-xs text-stone-500 tabular-nums">{{ entry.figures.baseAverage === null ? "—" : entry.figures.baseAverage.toFixed(2) }}</td>
                            <td class="px-2 text-right text-xs tabular-nums" :class="wapeClass(entry.figures)">{{ formatRate(entry.figures.wape, 0) }}</td>
                            <td
                                v-for="column in shown"
                                :key="column.column.key"
                                class="px-1.5 text-right text-xs leading-tight tabular-nums"
                                :class="[cellClass(cellOf(entry.figures, column.index, column.column).tone), column.column.kind === 'current' ? 'border-l border-dashed border-l-stone-400' : '']"
                            >
                                <span class="block">{{ cellOf(entry.figures, column.index, column.column).main }}</span>
                                <span class="block text-[10px]" :class="subClass(cellOf(entry.figures, column.index, column.column).tone)">{{
                                    cellOf(entry.figures, column.index, column.column).sub
                                }}</span>
                            </td>
                            <td class="px-2 text-right text-xs text-stone-700 tabular-nums">{{ formatFigure(entry.figures.forecastTotal, measure, "month") }}</td>
                        </tr>
                    </template>
                    <tr v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }">
                        <td :colspan="shown.length + 4" class="p-0" />
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
