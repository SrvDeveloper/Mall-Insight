<script setup lang="ts">
import { computed, ref, useTemplateRef } from "vue";
import { RouterLink, type RouteLocationRaw } from "vue-router";
import { formatFigure, type ForecastMeasure, type TimelineFigures, type TimelineItem, type TimelineLabel } from "@/components/forecast/timelineRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, ForecastTimelineSku, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面の表（B-131、K-123）。画面デザイン案のとおり、品番の行（開くとSKUの行）に、過去の月・日は上に販売実績、下に予測し直した数と差異を、
 * 今月（今日）から先は需要予測を並べる。差異が大きい月・日は薄い黄色、日ごとの欠品した日は濃い黄色にする。
 * 出す過去の月・日の数（月ごとは6か月・12か月、日ごとは7日・14日・30日）は呼び出し側が選ぶ。表は大きくなるため、見えている行だけを描く。
 */

const props = defineProps<{
    items: TimelineItem[];
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    events: ForecastTimelineResponse["meta"]["events"][];
    unit: SalesUnit;
    measure: ForecastMeasure;
    threshold: number;
    /** 出す過去の月・日の数 */
    pastCount: number;
    itemLink?: (itemNo: string) => RouteLocationRaw;
}>();

const ROW_HEIGHT = 45;
const isDay = computed(() => props.unit === "day");

const shown = computed(() => {
    const pastTotal = props.columns.filter((column) => column.kind === "past").length;
    const first = Math.max(0, pastTotal - props.pastCount);
    return props.columns
        .map((column, index) => ({ column, index, label: props.labels[index]!, events: props.events[index] ?? [] }))
        .slice(first)
        .map((entry, position) => ({
            ...entry,
            head: isDay.value ? entry.label.label : `${entry.label.sub || position === 0 ? `${entry.column.key.slice(0, 4)}/` : ""}${entry.label.label}`,
            sub: isDay.value
                ? `${entry.label.sub}${entry.column.kind === "current" ? "・今日" : ""}`
                : entry.column.kind === "past"
                  ? "実績 / 予測"
                  : entry.column.kind === "current"
                    ? "今月"
                    : "予測",
        }));
});

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

const formatGap = (value: number): string => `${value >= 0 ? "+" : "−"}${Math.abs(value * 100).toFixed(0)}%`;

/** 1つの欄の表示。 */
function cellsOf(figures: TimelineFigures) {
    return shown.value.map(({ column, index, events }) => {
        const weekend = isDay.value && props.labels[index]?.weekend;
        if (column.kind === "past") {
            const variance = figures.variance[index] ?? null;
            const replayed = figures.replayed[index];
            if (isDay.value && figures.stockout[index]) {
                return {
                    key: column.key,
                    main: formatFigure(figures.actual[index], props.measure, props.unit),
                    sub: "欠品（数えない）",
                    cellClass: "bg-amber-100",
                    subClass: "text-[10px] text-amber-800",
                };
            }
            const large = variance !== null && Math.abs(variance) >= props.threshold;
            return {
                key: column.key,
                main: formatFigure(figures.actual[index], props.measure, props.unit),
                sub: replayed === null || replayed === undefined ? "予測できません" : `${formatFigure(replayed, props.measure, props.unit)}（${variance === null ? "—" : formatGap(variance)}）`,
                cellClass: large ? "bg-amber-50" : "bg-stone-50",
                subClass: `${isDay.value ? "text-[10px]" : "text-[11px]"} ${large ? "font-semibold text-amber-700" : "text-stone-500"}`,
            };
        }
        const isCurrent = column.kind === "current";
        return {
            key: column.key,
            main: formatFigure(figures.forecast[index], props.measure, props.unit),
            sub:
                isCurrent && !isDay.value
                    ? `実績 ${formatFigure(figures.actual[index], props.measure, props.unit)}`
                    : events.length > 0
                      ? events.map((event) => event.name.slice(0, 4)).join("・")
                      : "",
            cellClass: `font-semibold${isCurrent ? " border-l border-dashed border-l-stone-400" : ""}${weekend ? " bg-stone-50" : ""}`,
            subClass: "text-[10px] font-normal text-stone-500",
        };
    });
}

const wapeText = (figures: TimelineFigures): string => (figures.wape === null ? "—" : `${(figures.wape * 100).toFixed(0)}%`);
const isLargeWape = (figures: TimelineFigures): boolean => figures.wape !== null && figures.wape >= props.threshold;
const variantOf = (sku: ForecastTimelineSku): string => [sku.color_no, sku.size].filter((value) => value !== null && value !== "").join("・");
const headWidth = (column: ForecastTimelineColumn): string => `${column.kind === "past" ? (isDay.value ? 76 : 92) : isDay.value ? 48 : 64}px`;
</script>

<template>
    <div
        ref="scrollBox"
        class="max-h-[820px] overflow-auto select-none"
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
        <table
            class="min-w-full border-separate border-spacing-0 text-[13px] [&_td]:border-b [&_td]:border-stone-100 [&_td]:px-2 [&_td]:py-1.5 [&_td]:tabular-nums [&_th]:px-2 [&_th]:py-1.5 [&_th]:tabular-nums"
        >
            <thead class="[&_th]:sticky [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white [&_th]:text-xs [&_th]:font-medium [&_th]:text-stone-500">
                <tr>
                    <th scope="col" class="left-0 z-[2] min-w-[200px] pl-4! text-left">品番</th>
                    <th scope="col" class="min-w-[72px] text-right">基準平均日販</th>
                    <th scope="col" class="min-w-16 text-right">
                        差異<span class="block text-[10px] font-normal text-stone-400">{{ isDay ? "外れ率（過去の日）" : "外れ率" }}</span>
                    </th>
                    <th
                        v-for="entry in shown"
                        :key="entry.column.key"
                        scope="col"
                        class="z-[1] text-right"
                        :class="[
                            entry.column.kind === 'past' ? 'bg-stone-50!' : '',
                            entry.column.kind === 'current' ? 'border-l border-dashed border-l-stone-400' : '',
                            isDay && entry.label.weekend ? 'text-stone-400!' : '',
                        ]"
                        :style="{ minWidth: headWidth(entry.column) }"
                    >
                        {{ entry.head }}<span class="block text-[10px] font-normal text-stone-400">{{ entry.sub }}</span>
                    </th>
                    <th scope="col" class="min-w-20 text-right">{{ isDay ? `${columns.filter((column) => column.kind !== "past").length}日の予測` : "12か月の予測" }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }">
                    <td :colspan="shown.length + 4" class="p-0" />
                </tr>
                <template v-for="entry in renderedRows" :key="entry.key">
                    <tr v-if="entry.kind === 'item'" class="bg-white" data-testid="table-item-row">
                        <th scope="row" class="sticky left-0 z-[1] border-b border-stone-200 bg-white pl-3! text-left font-medium">
                            <span class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    class="inline-flex size-[22px] cursor-pointer items-center justify-center p-0 text-stone-500"
                                    :aria-expanded="open.has(entry.item.itemNo)"
                                    :aria-label="`${entry.item.itemNo} のSKUを${open.has(entry.item.itemNo) ? '閉じる' : '開く'}`"
                                    :data-testid="`toggle-${entry.item.itemNo}`"
                                    @click="toggle(entry.item.itemNo)"
                                >
                                    <svg
                                        :class="open.has(entry.item.itemNo) ? 'rotate-90' : ''"
                                        width="14"
                                        height="14"
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
                                <span class="flex flex-col">
                                    <RouterLink v-if="itemLink" :to="itemLink(entry.item.itemNo)" class="font-mono text-sm font-medium text-stone-900 hover:underline" data-testid="table-item-link">{{
                                        entry.item.itemNo
                                    }}</RouterLink>
                                    <span v-else class="font-mono text-sm font-medium text-stone-900">{{ entry.item.itemNo }}</span>
                                    <span class="text-[11px] font-normal whitespace-nowrap text-stone-500"
                                        >{{ entry.item.brand }}・{{ entry.item.category
                                        }}<template v-if="entry.item.unpredictableCount > 0">・予測不能 {{ entry.item.unpredictableCount }} SKU</template></span
                                    >
                                </span>
                            </span>
                        </th>
                        <td class="text-right text-stone-600">{{ entry.item.figures.baseAverage === null ? "—" : entry.item.figures.baseAverage.toFixed(1) }}</td>
                        <td class="text-right" :class="isLargeWape(entry.item.figures) ? 'font-bold text-amber-700' : 'text-stone-600'" data-testid="table-wape">{{ wapeText(entry.item.figures) }}</td>
                        <td v-for="cell in cellsOf(entry.item.figures)" :key="cell.key" class="text-right" :class="cell.cellClass" data-testid="table-cell">
                            <span class="block text-stone-900">{{ cell.main }}</span>
                            <span class="block" :class="cell.subClass">{{ cell.sub }}</span>
                        </td>
                        <td class="text-right font-semibold" data-testid="table-total">{{ formatFigure(entry.item.figures.forecastTotal, measure, "month") }}</td>
                    </tr>
                    <tr v-else class="bg-white" data-testid="table-sku-row">
                        <th scope="row" class="sticky left-0 z-[1] border-b border-stone-100 bg-white pl-11! text-left font-normal">
                            <span class="flex flex-col">
                                <span class="font-mono text-xs text-stone-700">{{ entry.sku.sku_code }}</span>
                                <span class="text-[11px] whitespace-nowrap" :class="entry.sku.unpredictable_reason_label ? 'text-amber-700' : 'text-stone-500'">{{
                                    entry.sku.unpredictable_reason_label ? `予測不能：${entry.sku.unpredictable_reason_label}` : variantOf(entry.sku)
                                }}</span>
                            </span>
                        </th>
                        <td class="text-right text-stone-600">{{ entry.figures.baseAverage === null ? "—" : entry.figures.baseAverage.toFixed(1) }}</td>
                        <td class="text-right text-xs" :class="isLargeWape(entry.figures) ? 'font-semibold text-amber-700' : 'text-stone-500'">{{ wapeText(entry.figures) }}</td>
                        <td v-for="cell in cellsOf(entry.figures)" :key="cell.key" class="text-right" :class="cell.cellClass">
                            <span class="block text-stone-900">{{ cell.main }}</span>
                            <span class="block" :class="cell.subClass">{{ cell.sub }}</span>
                        </td>
                        <td class="text-right font-semibold">{{ formatFigure(entry.figures.forecastTotal, measure, "month") }}</td>
                    </tr>
                </template>
                <tr v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }">
                    <td :colspan="shown.length + 4" class="p-0" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
