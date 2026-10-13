<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useTemplateRef, watch, watchPostEffect } from "vue";
import type { ActualColumn, ChartSeries } from "@/components/actuals/actualRows";
import SalesTooltipContent from "@/components/actuals/SalesTooltipContent.vue";
import { buildSalesTooltip } from "@/components/actuals/salesTooltip";
import type { StoreSalesRow } from "@/components/actuals/storeSalesRows";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import type { SalesUnit } from "@/types/api";

/**
 * 店舗売上（B-126）の表。品番別売上の表（K-047・K-074）とそろえ、月・日を列に並べ、2列目の期間計と3列目の構成比は薄い灰色を敷いて区切る。
 * 1行目は店舗の合計。販売実績を取り込んでいない店舗を除いた合計には「※」を付ける。データの無い列は見出しを薄くし「—」で示す。
 * 合計の行の月・日の欄には (i) のボタンを置き、押したときだけ、グラフと同じ内訳（合計と店舗ごとの値・構成比・前月比・前年比、K-082）を
 * ボタンの下（下に入らなければ上）に出す（K-094）。背景は墨色を不透明度85%で透かす（K-085・K-091）。もう一度押す、外側を押す、Esc キー、
 * 表を横に動かすと閉じる。販売実績のある欄を押す（Enter キー）と、その店舗（合計の行なら全店舗）の売上上位を表の下に出し、
 * その欄を枠で示す（K-091・K-092）。
 */

const props = defineProps<{
    total: StoreSalesRow;
    stores: StoreSalesRow[];
    columns: ActualColumn[];
    summaryLabel: string;
    columnWidth: number;
    /** 内訳に使う、店舗ごとの値（表示している数か金額） */
    series: ChartSeries[];
    /** 内訳の値の表示（表の千円単位ではなく、円・個で出す） */
    formatValue: (value: number) => string;
    unit: SalesUnit;
    /** 売上上位を表の下に出している欄（列の位置と店舗。合計の行は null） */
    selected?: { index: number; key: string | null } | null;
}>();
const emit = defineEmits<{ pick: [index: number, key: string | null] }>();

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const tooltipElement = useTemplateRef<HTMLDivElement>("tooltipElement");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox, () => closeInfo());

/** 内訳を出している列と、(i) のボタンの画面上の位置。 */
const info = ref<{ index: number; rect: ReturnType<HTMLElement["getBoundingClientRect"]> } | null>(null);
const tooltipPosition = ref({ left: 0, top: 0 });

function closeInfo(): void {
    info.value = null;
}

function toggleInfo(event: Event, index: number): void {
    info.value = info.value?.index === index ? null : { index, rect: (event.currentTarget as HTMLElement).getBoundingClientRect() };
}

// 内訳を出している間は、外側を押す・Esc キーで閉じる
function onDocumentPointerDown(event: Event): void {
    const target = event.target as HTMLElement | null;
    if (target && (tooltipElement.value?.contains(target) || target.closest?.("[data-info-button]"))) {
        return;
    }
    closeInfo();
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeInfo();
    }
}

watch(
    () => info.value !== null,
    (isOpen) => {
        if (isOpen) {
            document.addEventListener("pointerdown", onDocumentPointerDown);
            document.addEventListener("keydown", onDocumentKeydown);
        } else {
            document.removeEventListener("pointerdown", onDocumentPointerDown);
            document.removeEventListener("keydown", onDocumentKeydown);
        }
    },
);
onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", onDocumentPointerDown);
    document.removeEventListener("keydown", onDocumentKeydown);
});

/** 欄が売上上位を出している欄か。 */
const isSelected = (index: number, key: string | null): boolean => props.selected?.index === index && props.selected.key === key;

/** 販売実績のある欄を押したら、売上上位を出す（ドラッグで横に動かしたときは出さない）。 */
function pick(index: number, key: string | null, hasData: boolean): void {
    if (!hasData || isDragging.value) {
        return;
    }
    emit("pick", index, key);
}

const tooltip = computed(() => (info.value ? buildSalesTooltip(props.series, props.columns[info.value.index]!, info.value.index, { kind: "bar", unit: props.unit, withShare: true }) : null));

// 表の内容が変わったら閉じる（期間などを切り替えたとき）
watch(
    () => [props.columns, props.series],
    () => closeInfo(),
);

// ボタンの下に置き、画面の下に入らなければ上に置く。左右は画面の幅に収める
watchPostEffect(() => {
    if (!info.value || !tooltipElement.value) {
        return;
    }
    const { rect } = info.value;
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
        @scroll="closeInfo"
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
                        class="px-1.5 font-semibold tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                        :class="[cell.tone === 'none' ? '' : 'cursor-pointer hover:bg-stone-100', isSelected(index, null) ? 'bg-stone-100 shadow-[inset_0_0_0_2px_var(--color-stone-900)]' : '']"
                        :data-selected="isSelected(index, null) || undefined"
                        tabindex="0"
                        @click="pick(index, null, cell.tone !== 'none')"
                        @keydown.enter.self="pick(index, null, cell.tone !== 'none')"
                    >
                        <span class="flex items-center justify-end gap-0.5 whitespace-nowrap">
                            <button
                                v-if="cell.tone !== 'none'"
                                type="button"
                                class="flex size-5 shrink-0 items-center justify-center rounded-full text-stone-400 hover:bg-stone-200 hover:text-stone-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                :class="info?.index === index ? 'bg-stone-200 text-stone-800' : ''"
                                :aria-label="`${columns[index]?.label ?? ''} の内訳`"
                                :aria-expanded="info?.index === index"
                                aria-controls="store-sales-info"
                                data-info-button
                                data-testid="info-button"
                                @click.stop="toggleInfo($event, index)"
                                @keydown.enter.stop
                            >
                                <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true">
                                    <circle cx="8" cy="8" r="6.25" />
                                    <path d="M8 7.25v3.75" />
                                    <circle cx="8" cy="4.9" r="0.4" fill="currentColor" />
                                </svg>
                            </button>
                            <span class="inline-flex min-w-8 justify-end px-1 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-900'" data-testid="store-cell"
                                >{{ cell.text }}<sup v-if="cell.excludes" class="ml-0.5 text-[10px] font-normal text-stone-400" data-testid="store-cell-excludes">※</sup></span
                            >
                        </span>
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
                        :class="[cell.tone === 'none' ? '' : 'cursor-pointer hover:bg-stone-100', isSelected(index, row.key) ? 'bg-stone-100 shadow-[inset_0_0_0_2px_var(--color-stone-900)]' : '']"
                        :data-selected="isSelected(index, row.key) || undefined"
                        tabindex="0"
                        @click="pick(index, row.key, cell.tone !== 'none')"
                        @keydown.enter="pick(index, row.key, cell.tone !== 'none')"
                    >
                        <span class="inline-flex min-w-8 justify-end px-2 py-0.5" :class="cell.tone === 'none' ? 'text-stone-300' : 'text-stone-700'" data-testid="store-cell">{{ cell.text }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="tooltip && info"
            id="store-sales-info"
            ref="tooltipElement"
            role="tooltip"
            class="fixed z-40 w-80 max-w-[calc(100vw-1rem)] rounded-lg bg-stone-900/85 px-3 py-2 text-xs text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltipPosition.left}px`, top: `${tooltipPosition.top}px` }"
            data-testid="table-tooltip"
        >
            <SalesTooltipContent :tooltip="tooltip" :format-value="formatValue" :unit="unit" />
        </div>
    </div>
</template>
