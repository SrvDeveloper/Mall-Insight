<script setup lang="ts">
import { computed, useTemplateRef } from "vue";
import TrendTooltip from "@/components/inventoryTrend/TrendTooltip.vue";
import { COVER_COLORS, shortMonthLabel, type ItemRows, type SkuRow, type TrendMonth, type TrendTooltipContent } from "@/components/inventoryTrend/trendRows";
import { useAnchoredTooltip } from "@/composables/useAnchoredTooltip";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";

/**
 * 在庫推移の「タイムライン」（K-047、デザイン案A-2）。SKUごとに、在庫がいつまで持つかを月ごとの帯の濃さで示す
 * （在庫が持つ月数 ＝ 月末在庫 ÷ 平均月販）。足りない月は赤、在庫0の月は黄。右に「1月から欠品」「12か月もつ」の札を出す。
 * 品番の行には、入荷のある月に「+600」の札を出す。表と同じく、見えている行だけを描く。
 */

const props = defineProps<{ items: ItemRows[]; months: TrendMonth[]; checkMonthIndex: number; stockDate: string | null }>();

const ROW_HEIGHT = 44;

type FlatRow = { kind: "item"; key: string; group: ItemRows } | { kind: "sku"; key: string; row: SkuRow };

const flatRows = computed<FlatRow[]>(() =>
    props.items.flatMap((group): FlatRow[] => [{ kind: "item", key: `item-${group.item.item_no}`, group }, ...group.rows.map((row): FlatRow => ({ kind: "sku", key: `sku-${row.sku.sku_id}`, row }))]),
);

/** 品番・SKU（300px）、12か月（各64px）、欠品が始まる月（150px） */
const gridColumns = computed(() => ({ gridTemplateColumns: `300px repeat(${props.months.length}, 64px) 150px` }));

const tooltipElement = useTemplateRef<HTMLElement>("tooltipElement");
const { tooltip, show: showTooltip, hide: hideTooltip } = useAnchoredTooltip<TrendTooltipContent>(tooltipElement);

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox, hideTooltip);
const { onScroll, renderedRows, topSpacerHeight, bottomSpacerHeight } = useVirtualRows(flatRows, scrollBox, hideTooltip, ROW_HEIGHT);

function monthContent(row: SkuRow, index: number): TrendTooltipContent {
    return { kind: "month", sku: row.sku, month: row.sku.months![index]!, isCheckMonth: index === props.checkMonthIndex };
}

function columnTint(index: number): string {
    return index === props.checkMonthIndex ? "bg-stone-100" : "";
}
</script>

<template>
    <div
        ref="scrollBox"
        class="max-h-[calc(100dvh-5rem)] overflow-auto select-none"
        :class="isDragging ? 'cursor-grabbing' : ''"
        data-testid="trend-timeline"
        @scroll="onScroll"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
        @lostpointercapture="onPointerEnd"
        @pointerleave="onPointerLeave"
    >
        <div class="min-w-max text-[13px]">
            <div class="sticky top-0 z-20 grid items-end border-b border-stone-300 bg-white text-xs font-medium text-stone-500" :style="gridColumns">
                <div class="sticky left-0 z-10 bg-white px-5 pt-3 pb-2">品番・SKU</div>
                <div v-for="(month, index) in months" :key="month.month" class="flex justify-center px-0.5 pt-2 pb-2" :class="columnTint(index)">
                    <span
                        class="inline-flex min-w-12 flex-col items-center rounded-lg px-1 py-1 leading-tight tabular-nums"
                        :class="index === checkMonthIndex ? 'bg-stone-900 font-semibold text-white' : ''"
                    >
                        {{ shortMonthLabel(month.month) }}
                        <span class="text-[10px] font-normal" :class="index === checkMonthIndex ? 'text-stone-300' : 'text-stone-400'">{{
                            index === 0 ? `${month.days}日分` : index === checkMonthIndex ? "判定" : "&nbsp;"
                        }}</span>
                    </span>
                </div>
                <div class="px-4 pt-3 pb-2">欠品が始まる月</div>
            </div>

            <div v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }" />
            <template v-for="entry in renderedRows" :key="entry.key">
                <div v-if="entry.kind === 'item'" class="grid h-11 items-center border-t border-stone-200 bg-stone-50" :style="gridColumns" data-testid="timeline-item-row">
                    <div class="sticky left-0 z-10 flex h-full items-center gap-2 truncate bg-stone-50 px-5">
                        <span class="font-mono text-sm font-medium text-stone-900">{{ entry.group.item.item_no }}</span>
                        <span class="truncate rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ entry.group.item.brand }}・{{ entry.group.item.category }}</span>
                    </div>
                    <div v-for="(inbound, index) in entry.group.monthInbounds" :key="index" class="flex h-full items-center justify-center px-0.5" :class="columnTint(index)">
                        <span
                            v-if="inbound"
                            class="inline-flex items-center gap-0.5 rounded-full border border-stone-300 bg-white px-1.5 text-[11px] font-semibold text-stone-700 tabular-nums"
                            :title="inbound.label"
                            data-testid="timeline-inbound"
                        >
                            <svg
                                class="size-2.5 text-stone-500"
                                viewBox="0 0 16 16"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M2.5 6.5v6.5h11V6.5" />
                                <path d="M1.5 6.5h13" />
                                <path d="M8 1.5v6M5.5 5 8 7.5 10.5 5" />
                            </svg>
                            {{ inbound.text }}
                        </span>
                    </div>
                    <div class="truncate px-4 text-xs" :class="entry.group.shortageCount > 0 ? 'font-semibold text-red-700' : 'text-stone-500'">
                        {{ entry.group.shortageCount > 0 ? `判定月に ${entry.group.shortageCount} SKU 欠品` : "判定月に欠品なし" }}
                    </div>
                </div>
                <div v-else class="group grid h-11 items-center border-t border-stone-100 hover:bg-stone-50" :style="gridColumns" data-testid="timeline-sku-row">
                    <div class="sticky left-0 z-10 flex h-full items-center justify-between gap-2 bg-white pr-4 pl-8 group-hover:bg-stone-50">
                        <span class="truncate font-mono text-[12.5px] text-stone-700" :title="entry.row.sku.sku_code">{{ entry.row.sku.sku_code }}</span>
                        <span class="shrink-0 text-[11px] text-stone-500 tabular-nums">{{ entry.row.coverText }}</span>
                    </div>
                    <template v-if="entry.row.sku.months">
                        <div v-for="(cell, index) in entry.row.cells" :key="index" class="flex h-full items-center px-0.5" :class="columnTint(index)">
                            <span
                                tabindex="0"
                                class="block h-4.5 w-full cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-offset-1"
                                :class="COVER_COLORS[cell.cover]"
                                :aria-label="`${shortMonthLabel(months[index]!.month)} ${cell.text}`"
                                data-testid="timeline-block"
                                :data-cover="cell.cover"
                                @mouseenter="showTooltip($event, monthContent(entry.row, index))"
                                @mouseleave="hideTooltip"
                                @focus="showTooltip($event, monthContent(entry.row, index), 0)"
                                @blur="hideTooltip"
                            />
                        </div>
                        <div class="px-4">
                            <span
                                v-if="entry.row.status"
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="entry.row.status.isShortage ? 'bg-red-100 text-red-700' : 'bg-stone-100 text-stone-600'"
                                data-testid="timeline-status"
                            >
                                {{ entry.row.status.label }}
                            </span>
                        </div>
                    </template>
                    <div v-else class="truncate px-2" :style="{ gridColumn: `span ${months.length + 1}` }" :title="entry.row.sku.forecast_reason_label ?? entry.row.sku.status_label">
                        <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-500">{{ entry.row.sku.status_label }}</span>
                    </div>
                </div>
            </template>
            <div v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }" />
        </div>

        <div
            v-if="tooltip.content"
            ref="tooltipElement"
            role="tooltip"
            class="pointer-events-none fixed z-50 w-56 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg bg-stone-900 text-left text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }"
        >
            <TrendTooltip :content="tooltip.content" :stock-date="stockDate" />
        </div>
    </div>
</template>
