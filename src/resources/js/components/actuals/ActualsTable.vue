<script setup lang="ts">
import { computed, useTemplateRef } from "vue";
import type { ActualCell, ActualColumn, ActualItemRows, ActualSkuRow } from "@/components/actuals/actualRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";

/**
 * 実績（B-124）の表。月（販売実績）か日（在庫実績）を列に並べ、品番の行に合計を出す。在庫推移の表（K-047・K-074）とそろえ、
 * 2列目（販売実績は期間の合計、在庫実績は最新の在庫）は薄い灰色を敷いて区切り線を入れる。在庫0は赤い角丸の札、データの無い列は
 * 見出しを薄くし「—」で示す。表は大きくなるため、見えている行だけを描く。
 */

const props = defineProps<{ items: ActualItemRows[]; columns: ActualColumn[]; summaryLabel: string; notSoldLabel: string; fill?: boolean }>();

const ROW_HEIGHT = 40;

type FlatRow = { kind: "item"; key: string; group: ActualItemRows } | { kind: "sku"; key: string; row: ActualSkuRow };

const flatRows = computed<FlatRow[]>(() =>
    props.items.flatMap((group): FlatRow[] => [{ kind: "item", key: `item-${group.item.item_no}`, group }, ...group.rows.map((row): FlatRow => ({ kind: "sku", key: `sku-${row.sku.sku_id}`, row }))]),
);

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox);
const { onScroll, renderedRows, topSpacerHeight, bottomSpacerHeight } = useVirtualRows(flatRows, scrollBox, undefined, ROW_HEIGHT);

const SUMMARY_EDGE = "shadow-[inset_-1px_0_0_var(--color-stone-300)]";

function cellClass(cell: ActualCell): string {
    return cell.tone === "empty" ? "bg-red-100 font-semibold text-red-700" : cell.tone === "none" ? "text-stone-300" : "text-stone-700";
}
</script>

<template>
    <div
        ref="scrollBox"
        class="overflow-auto select-none"
        :class="[fill ? 'min-h-0 flex-1' : 'max-h-[calc(100dvh-5rem)]', isDragging ? 'cursor-grabbing' : '']"
        data-testid="actuals-table"
        @scroll="onScroll"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
        @lostpointercapture="onPointerEnd"
        @pointerleave="onPointerLeave"
    >
        <table class="w-full table-fixed border-separate border-spacing-0 text-left text-sm" :style="{ minWidth: `${(248 + 88 + columns.length * 72) / 16}rem` }">
            <colgroup>
                <col class="w-62" />
                <col class="w-22" />
                <col v-for="column in columns" :key="column.key" class="w-18" />
            </colgroup>
            <thead class="[&_th]:sticky [&_th]:top-0 [&_th]:z-20 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                <tr class="text-xs font-medium text-stone-500">
                    <th scope="col" class="left-0 z-30! px-4 pt-1 pb-2">品番・SKU</th>
                    <th scope="col" :class="`bg-stone-50! px-3 pt-1 pb-2 text-right ${SUMMARY_EDGE}`">{{ summaryLabel }}</th>
                    <th v-for="column in columns" :key="column.key" scope="col" class="px-1 pt-1 pb-2 text-center" :data-muted="column.muted">
                        <span class="inline-flex min-w-12 flex-col items-center px-1 py-1 leading-tight tabular-nums" :class="column.muted ? 'text-stone-300' : ''">
                            {{ column.label }}
                            <span class="text-[10px] font-normal" :class="column.muted ? 'text-stone-300' : 'text-stone-400'">{{ column.sub || "&nbsp;" }}</span>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }">
                    <td :colspan="columns.length + 2" class="p-0" />
                </tr>
                <template v-for="entry in renderedRows" :key="entry.key">
                    <tr v-if="entry.kind === 'item'" class="h-[40px] bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="item-row">
                        <th scope="rowgroup" class="sticky left-0 z-10 bg-stone-50 px-4 text-left font-normal">
                            <div class="flex items-center gap-1.5 overflow-hidden whitespace-nowrap">
                                <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ entry.group.item.item_no }}</span>
                                <span class="min-w-0 truncate rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600"
                                    >{{ entry.group.item.brand }}・{{ entry.group.item.category }}</span
                                >
                            </div>
                        </th>
                        <td :class="`bg-stone-100 px-3 text-right font-semibold text-stone-900 tabular-nums ${SUMMARY_EDGE}`" data-testid="item-summary">{{ entry.group.summaryText }}</td>
                        <td v-for="(cell, index) in entry.group.totals" :key="index" class="px-2 text-right font-semibold tabular-nums">
                            <span class="inline-flex min-w-8 justify-end rounded-md px-2 py-0.5" :class="cell.tone === 'normal' ? 'text-stone-900' : cellClass(cell)">{{ cell.text }}</span>
                        </td>
                    </tr>
                    <tr v-else class="group h-[40px] hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="sku-row">
                        <td class="sticky left-0 z-10 truncate bg-white py-0 pr-4 pl-7 font-mono text-sm text-stone-700 group-hover:bg-stone-50" :title="entry.row.sku.sku_code">
                            {{ entry.row.sku.sku_code }}
                            <span v-if="entry.row.sku.status === 'inactive'" class="ml-1 rounded-full bg-stone-100 px-1.5 py-px font-sans text-[10px] text-stone-500">{{
                                entry.row.sku.status_label
                            }}</span>
                        </td>
                        <td :class="`bg-stone-50 px-3 text-right font-medium text-stone-900 tabular-nums group-hover:bg-stone-100 ${SUMMARY_EDGE}`" data-testid="sku-summary">
                            {{ entry.row.summaryText }}
                        </td>
                        <td v-if="entry.row.sku.not_sold" :colspan="columns.length" class="truncate px-3" data-testid="not-sold">
                            <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-500">{{ notSoldLabel }}</span>
                        </td>
                        <td v-for="(cell, index) in entry.row.cells" v-else :key="index" class="px-2 text-right tabular-nums">
                            <span class="inline-flex min-w-8 justify-end rounded-md px-2 py-0.5" :class="cellClass(cell)" data-testid="actual-cell">{{ cell.text }}</span>
                        </td>
                    </tr>
                </template>
                <tr v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }">
                    <td :colspan="columns.length + 2" class="p-0" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
