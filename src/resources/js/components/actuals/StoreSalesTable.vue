<script setup lang="ts">
import { computed, ref, useTemplateRef, watch, watchPostEffect } from "vue";
import type { ActualColumn, ChartSeries } from "@/components/actuals/actualRows";
import SalesTooltipContent from "@/components/actuals/SalesTooltipContent.vue";
import { buildSalesTooltip } from "@/components/actuals/salesTooltip";
import type { StoreSalesRow } from "@/components/actuals/storeSalesRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import type { SalesUnit } from "@/types/api";

/**
 * 店舗売上（B-126）の表。品番別売上の表（K-047・K-074）とそろえ、月・日を列に並べ、2列目の期間計と3列目の構成比は薄い灰色を敷いて区切る。
 * 1行目は店舗の合計。販売実績を取り込んでいない店舗を除いた合計には「※」を付ける。データの無い列は見出しを薄くし「—」で示す。
 * 月・日の欄にマウスを乗せる（またはフォーカスする）と、グラフと同じツールチップ（合計と店舗ごとの値・構成比・前月比・前年比、K-082）を
 * 欄の下（下に入らなければ上）に出し、マウスを乗せた店舗を目立たせる。ドラッグで横に動かしている間は出さない。
 */

const props = defineProps<{
    total: StoreSalesRow;
    stores: StoreSalesRow[];
    columns: ActualColumn[];
    summaryLabel: string;
    columnWidth: number;
    /** ツールチップに使う、店舗ごとの値（表示している数か金額） */
    series: ChartSeries[];
    /** ツールチップの値の表示（表の千円単位ではなく、円・個で出す） */
    formatValue: (value: number) => string;
    unit: SalesUnit;
}>();

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const tooltipElement = useTemplateRef<HTMLDivElement>("tooltipElement");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox, () => {
    hovered.value = null;
});

/** マウスを乗せた欄（列の位置と店舗。合計の行は null）と、その欄の画面上の位置。 */
const hovered = ref<{ index: number; key: string | null; rect: ReturnType<HTMLElement["getBoundingClientRect"]> } | null>(null);
const tooltipPosition = ref({ left: 0, top: 0 });

function show(event: Event, index: number, key: string | null): void {
    if (isDragging.value) {
        return;
    }
    hovered.value = { index, key, rect: (event.currentTarget as HTMLElement).getBoundingClientRect() };
}

function hide(): void {
    hovered.value = null;
}

const tooltip = computed(() => (hovered.value ? buildSalesTooltip(props.series, props.columns[hovered.value.index]!, hovered.value.index, { kind: "bar", unit: props.unit, withShare: true }) : null));

// 表の内容が変わったら閉じる（期間などを切り替えたとき）
watch(
    () => [props.columns, props.series],
    () => hide(),
);

// 欄の下に置き、画面の下に入らなければ上に置く。左右は画面の幅に収める
watchPostEffect(() => {
    if (!hovered.value || !tooltipElement.value) {
        return;
    }
    const { rect } = hovered.value;
    const bounds = tooltipElement.value.getBoundingClientRect();
    const width = bounds.width || 320;
    const height = bounds.height || 0;
    const gap = 6;
    const margin = 8;
    const left = Math.min(Math.max(margin, rect.left + rect.width / 2 - width / 2), Math.max(margin, window.innerWidth - width - margin));
    const below = rect.bottom + gap;
    const top = below + height + margin <= window.innerHeight ? below : Math.max(margin, rect.top - height - gap);
    tooltipPosition.value = { left, top };
});

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
        @scroll="hide"
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
                    <td
                        v-for="(cell, index) in total.cells"
                        :key="index"
                        class="px-2 text-right font-semibold tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                        :class="hovered?.index === index && hovered.key === null ? 'bg-stone-200/70' : ''"
                        tabindex="0"
                        @mouseenter="show($event, index, null)"
                        @mouseleave="hide"
                        @focus="show($event, index, null)"
                        @blur="hide"
                    >
                        <span class="inline-flex min-w-8 justify-end px-2 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-900'" data-testid="store-cell"
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
                    <td
                        v-for="(cell, index) in row.cells"
                        :key="index"
                        class="px-2 text-right tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                        :class="hovered?.index === index && hovered.key === row.key ? 'bg-stone-200/70' : ''"
                        tabindex="0"
                        @mouseenter="show($event, index, row.key)"
                        @mouseleave="hide"
                        @focus="show($event, index, row.key)"
                        @blur="hide"
                    >
                        <span class="inline-flex min-w-8 justify-end px-2 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-700'" data-testid="store-cell">{{ cell.text }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="tooltip && hovered"
            ref="tooltipElement"
            role="tooltip"
            class="pointer-events-none fixed z-40 w-80 max-w-[calc(100vw-1rem)] rounded-lg bg-stone-900 px-3 py-2 text-xs text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltipPosition.left}px`, top: `${tooltipPosition.top}px` }"
            data-testid="table-tooltip"
        >
            <SalesTooltipContent :tooltip="tooltip" :format-value="formatValue" :unit="unit" :highlight-key="hovered.key" />
        </div>
    </div>
</template>
