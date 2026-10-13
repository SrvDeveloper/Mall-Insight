<script setup lang="ts">
import { useTemplateRef } from "vue";
import type { ActualColumn } from "@/components/actuals/actualRows";
import type { StoreSalesRow } from "@/components/actuals/storeSalesRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";

/**
 * 店舗売上（B-126）の表。品番別売上の表（K-047・K-074）とそろえ、月・日を列に並べ、2列目の期間計と3列目の構成比は薄い灰色を敷いて区切る。
 * 1行目は店舗の合計。販売実績を取り込んでいない店舗を除いた合計には「※」を付け、ホバーで除いた店舗を出す。データの無い列は見出しを薄くし「—」で示す。
 */

defineProps<{ total: StoreSalesRow; stores: StoreSalesRow[]; columns: ActualColumn[]; summaryLabel: string; columnWidth: number }>();

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox);

const SUMMARY_EDGE = "shadow-[inset_-1px_0_0_var(--color-stone-300)]";
</script>

<template>
    <div
        ref="scrollBox"
        class="overflow-x-auto select-none"
        :class="isDragging ? 'cursor-grabbing' : ''"
        data-testid="store-sales-table"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
        @lostpointercapture="onPointerEnd"
        @pointerleave="onPointerLeave"
    >
        <table class="w-full table-fixed border-separate border-spacing-0 text-left text-sm" :style="{ minWidth: `${(208 + 96 + 72 + columns.length * columnWidth) / 16}rem` }">
            <colgroup>
                <col class="w-52" />
                <col class="w-24" />
                <col class="w-18" />
                <col v-for="column in columns" :key="column.key" :style="{ width: `${columnWidth}px` }" />
            </colgroup>
            <thead class="[&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                <tr class="text-xs font-medium text-stone-500">
                    <th scope="col" class="sticky left-0 z-20 px-4 pt-1 pb-2">店舗</th>
                    <th scope="col" class="bg-stone-50! px-3 pt-1 pb-2 text-right">{{ summaryLabel }}</th>
                    <th scope="col" :class="`bg-stone-50! px-3 pt-1 pb-2 text-right ${SUMMARY_EDGE}`">構成比</th>
                    <th v-for="column in columns" :key="column.key" scope="col" class="px-1 pt-1 pb-2 text-center" :data-muted="column.muted">
                        <span class="inline-flex min-w-12 flex-col items-center px-1 py-1 leading-tight tabular-nums" :class="column.muted ? 'text-stone-300' : ''">
                            {{ column.label }}
                            <span class="text-[10px] font-normal" :class="column.muted ? 'text-stone-300' : 'text-stone-400'">{{ column.sub || "&nbsp;" }}</span>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr class="h-[44px] bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="store-total-row">
                    <th scope="row" class="sticky left-0 z-10 bg-stone-50 px-4 text-left font-semibold text-stone-900">{{ total.label }}</th>
                    <td class="bg-stone-100 px-3 text-right font-semibold text-stone-900 tabular-nums" data-testid="store-summary">{{ total.summaryText }}</td>
                    <td :class="`bg-stone-100 px-3 text-right text-stone-500 tabular-nums ${SUMMARY_EDGE}`">{{ total.shareText }}</td>
                    <td v-for="(cell, index) in total.cells" :key="index" class="px-2 text-right font-semibold tabular-nums">
                        <span class="inline-flex min-w-8 justify-end px-2 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-900'" :title="cell.title" data-testid="store-cell"
                            >{{ cell.text }}<sup v-if="cell.excludes" class="ml-0.5 text-[10px] font-normal text-stone-400" data-testid="store-cell-excludes">※</sup></span
                        >
                    </td>
                </tr>
                <tr v-for="row in stores" :key="row.key" class="group h-[44px] hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="store-row">
                    <th scope="row" class="sticky left-0 z-10 bg-white px-4 text-left font-normal text-stone-800 group-hover:bg-stone-50">
                        <span class="flex items-center gap-2 overflow-hidden whitespace-nowrap">
                            <span class="size-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: row.color ?? undefined }" aria-hidden="true" />
                            <span class="truncate">{{ row.label }}</span>
                        </span>
                    </th>
                    <td class="bg-stone-50 px-3 text-right font-medium text-stone-900 tabular-nums group-hover:bg-stone-100" data-testid="store-summary">{{ row.summaryText }}</td>
                    <td :class="`bg-stone-50 px-3 text-right text-stone-500 tabular-nums group-hover:bg-stone-100 ${SUMMARY_EDGE}`" data-testid="store-share">{{ row.shareText }}</td>
                    <td v-for="(cell, index) in row.cells" :key="index" class="px-2 text-right tabular-nums">
                        <span class="inline-flex min-w-8 justify-end px-2 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-700'" :title="cell.title" data-testid="store-cell">{{
                            cell.text
                        }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
