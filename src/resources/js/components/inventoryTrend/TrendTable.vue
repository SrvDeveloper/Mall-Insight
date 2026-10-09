<script setup lang="ts">
import { computed, useTemplateRef } from "vue";
import TrendTooltip from "@/components/inventoryTrend/TrendTooltip.vue";
import { formatQuantity, isShort, shortMonthLabel, type CellTone, type ItemRows, type SkuRow, type TrendMonth, type StockScope, type TrendTooltipContent } from "@/components/inventoryTrend/trendRows";
import { useAnchoredTooltip } from "@/composables/useAnchoredTooltip";
import { useHorizontalDragScroll } from "@/composables/useHorizontalDragScroll";
import { useVirtualRows } from "@/composables/useVirtualRows";

/**
 * 在庫推移の「表」（K-047、デザイン案A-1）。月ごとの月末在庫を並べ、在庫不足（足りない数と在庫0）は赤い角丸の札で示す。
 * 品番の行に入荷アイコンと「欠品 3/5」の札を付ける。判定する月は、見出しを墨色の札にし、列に薄い色を敷く。
 * 現在庫（在庫基準日の実績）は、月末在庫の見込みと取り違えないよう、見出しに在庫基準日を添え、列に薄い色を敷いて、
 * 月の列との間に区切り線を入れる。年は月の見出し（26/10）で分かるため段を設けない。
 * 途中の月から計算できないSKU（販売試算、K-109）は、その月から後を斜線の欄にまとめて理由を出す。
 * 表は大きくなるため、見えている行だけを描く（行の高さは ROW_HEIGHT にそろえる）。全画面表示のとき（fill）は、残りの高さいっぱいに広げる。
 */

const props = defineProps<{ items: ItemRows[]; months: TrendMonth[]; checkMonthIndex: number; stockDate: string | null; demandLabel: string; stockScope: StockScope; fill?: boolean }>();

const ROW_HEIGHT = 40;

type FlatRow = { kind: "item"; key: string; group: ItemRows } | { kind: "sku"; key: string; row: SkuRow };

const flatRows = computed<FlatRow[]>(() =>
    props.items.flatMap((group): FlatRow[] => [{ kind: "item", key: `item-${group.item.item_no}`, group }, ...group.rows.map((row): FlatRow => ({ kind: "sku", key: `sku-${row.sku.sku_id}`, row }))]),
);
const formatStockDate = (value: string): string => `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`;

const tooltipElement = useTemplateRef<HTMLElement>("tooltipElement");
const { tooltip, show: showTooltip, hide: hideTooltip } = useAnchoredTooltip<TrendTooltipContent>(tooltipElement);

const scrollBox = useTemplateRef<HTMLDivElement>("scrollBox");
const { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave } = useHorizontalDragScroll(scrollBox, hideTooltip);
const { onScroll, renderedRows, topSpacerHeight, bottomSpacerHeight } = useVirtualRows(flatRows, scrollBox, hideTooltip, ROW_HEIGHT);

/** 数字の札の色（在庫不足＝足りない数と在庫0は赤い角丸の札。それ以外は札を付けない） */
function pillClass(tone: CellTone): string {
    return tone === "shortage" || tone === "empty" ? "bg-red-100 font-semibold text-red-700" : "text-stone-700";
}

function monthContent(row: SkuRow, index: number): TrendTooltipContent {
    return { kind: "month", sku: row.sku, month: row.sku.months![index]!, isCheckMonth: index === props.checkMonthIndex };
}
</script>

<template>
    <div
        ref="scrollBox"
        class="overflow-auto select-none"
        :class="[fill ? 'min-h-0 flex-1' : 'max-h-[calc(100dvh-5rem)]', isDragging ? 'cursor-grabbing' : '']"
        data-testid="trend-table"
        @scroll="onScroll"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerEnd"
        @pointercancel="onPointerEnd"
        @lostpointercapture="onPointerEnd"
        @pointerleave="onPointerLeave"
    >
        <table class="w-full min-w-[81rem] table-fixed border-separate border-spacing-0 text-left text-sm">
            <colgroup>
                <col class="w-62" />
                <col class="w-22" />
                <col v-for="month in months" :key="month.month" class="w-20" />
            </colgroup>
            <thead class="[&_th]:sticky [&_th]:z-20 [&_th]:bg-white">
                <tr class="text-xs font-medium text-stone-500 [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-300">
                    <th scope="col" class="left-0 z-30! px-4 pt-1 pb-2">品番・SKU</th>
                    <th scope="col" class="bg-stone-50! px-3 pt-1 pb-2 text-right shadow-[inset_-1px_0_0_var(--color-stone-300)]">
                        <span class="inline-flex flex-col items-end py-1 leading-tight">
                            現在庫
                            <span class="text-[10px] font-normal text-stone-400" data-testid="stock-date">{{ stockDate ? `${formatStockDate(stockDate)}時点` : "未取得" }}</span>
                        </span>
                    </th>
                    <th v-for="(month, index) in months" :key="month.month" scope="col" class="px-1 pt-1 pb-2 text-center">
                        <span
                            class="inline-flex min-w-14 flex-col items-center rounded-lg px-1.5 py-1 leading-tight tabular-nums"
                            :class="index === checkMonthIndex ? 'bg-stone-900 font-semibold text-white' : ''"
                        >
                            {{ shortMonthLabel(month.month) }}
                            <span class="text-[10px] font-normal" :class="index === checkMonthIndex ? 'text-stone-300' : 'text-stone-400'">{{
                                index === 0 ? "今月" : index === checkMonthIndex ? "判定" : "&nbsp;"
                            }}</span>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="topSpacerHeight > 0" aria-hidden="true" :style="{ height: `${topSpacerHeight}px` }">
                    <td :colspan="months.length + 2" class="p-0" />
                </tr>
                <template v-for="entry in renderedRows" :key="entry.key">
                    <tr v-if="entry.kind === 'item'" class="h-[40px] bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="item-row">
                        <th scope="rowgroup" class="sticky left-0 z-10 bg-stone-50 px-4 text-left font-normal">
                            <div class="flex items-center gap-1.5 overflow-hidden whitespace-nowrap">
                                <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ entry.group.item.item_no }}</span>
                                <span class="min-w-0 truncate rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600"
                                    >{{ entry.group.item.brand }}・{{ entry.group.item.category }}</span
                                >
                                <span v-if="entry.group.shortageCount > 0" class="shrink-0 rounded-full bg-red-100 px-2 py-px text-[11px] font-semibold text-red-700" data-testid="item-shortage">
                                    欠品 {{ entry.group.shortageCount }}/{{ entry.group.calculatedCount }}
                                </span>
                                <span
                                    v-if="isShort(entry.group.shortfallToCheck)"
                                    class="shrink-0 text-[11px] font-semibold text-red-700 tabular-nums"
                                    title="今月から判定する月までの、SKUの足りない数の合計"
                                    data-testid="item-shortfall"
                                >
                                    判定月まで −{{ formatQuantity(entry.group.shortfallToCheck) }}
                                </span>
                                <span
                                    v-if="entry.group.item.unassignable_inbound > 0"
                                    class="min-w-0 truncate text-[11px] text-amber-700"
                                    :title="`需要の出せるSKUが無く、未割り振りの入荷予定 ${entry.group.item.unassignable_inbound} を推移に入れられません`"
                                    data-testid="unassignable-inbound"
                                >
                                    入荷を割り振れません
                                </span>
                            </div>
                        </th>
                        <td class="bg-stone-100 px-3 text-right font-semibold text-stone-900 tabular-nums shadow-[inset_-1px_0_0_var(--color-stone-300)]">{{ entry.group.stockText }}</td>
                        <td
                            v-for="(text, index) in entry.group.monthTexts"
                            :key="index"
                            class="px-3 text-right font-semibold tabular-nums"
                            :class="[entry.group.monthTones[index] === 'shortage' ? 'text-red-700' : 'text-stone-900', index === checkMonthIndex ? 'bg-stone-200/70' : '']"
                        >
                            <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                <svg
                                    v-if="entry.group.monthInbounds[index]"
                                    class="size-3.5 shrink-0 text-stone-400"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    role="img"
                                    :aria-label="entry.group.monthInbounds[index]!.label"
                                    data-testid="inbound-icon"
                                >
                                    <title>{{ entry.group.monthInbounds[index]!.label }}</title>
                                    <path d="M2.5 6.5v6.5h11V6.5" />
                                    <path d="M1.5 6.5h13" />
                                    <path d="M8 1.5v6M5.5 5 8 7.5 10.5 5" />
                                </svg>
                                <template v-if="text !== null">{{ text }}</template>
                                <span v-else class="font-normal text-stone-300">—</span>
                            </span>
                        </td>
                    </tr>
                    <tr v-else class="group h-[40px] hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="sku-row">
                        <td class="sticky left-0 z-10 truncate bg-white py-0 pr-4 pl-7 font-mono text-sm text-stone-700 group-hover:bg-stone-50" :title="entry.row.sku.sku_code">
                            {{ entry.row.sku.sku_code }}
                            <span v-if="entry.row.sku.is_partial_forecast" class="ml-1 font-sans text-[10px] font-medium text-amber-700" :title="entry.row.sku.forecast_reason_label ?? undefined"
                                >需要一部</span
                            >
                        </td>
                        <td class="bg-stone-50 px-3 text-right whitespace-nowrap group-hover:bg-stone-100 shadow-[inset_-1px_0_0_var(--color-stone-300)]" data-testid="stock-td">
                            <span
                                v-if="entry.row.sku.stock_by_warehouse"
                                tabindex="0"
                                class="cursor-help font-medium text-stone-900 tabular-nums hover:underline hover:decoration-stone-400 hover:decoration-dotted hover:underline-offset-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                data-testid="stock"
                                @mouseenter="showTooltip($event, { kind: 'stock', sku: entry.row.sku })"
                                @mouseleave="hideTooltip"
                                @focus="showTooltip($event, { kind: 'stock', sku: entry.row.sku }, 0)"
                                @blur="hideTooltip"
                            >
                                {{ entry.row.stockText }}
                            </span>
                            <span v-else class="text-stone-300">—</span>
                        </td>
                        <template v-if="entry.row.sku.months">
                            <!-- ツールチップは数字だけでなく欄全体にマウスを乗せたときに出す -->
                            <td
                                v-for="(cell, index) in entry.row.cells"
                                :key="index"
                                tabindex="0"
                                class="cursor-help px-2 text-right hover:bg-stone-200/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                                :class="index === checkMonthIndex ? 'bg-stone-100' : ''"
                                data-testid="month-td"
                                @mouseenter="showTooltip($event, monthContent(entry.row, index))"
                                @mouseleave="hideTooltip"
                                @focus="showTooltip($event, monthContent(entry.row, index), 0)"
                                @blur="hideTooltip"
                            >
                                <span class="inline-flex min-w-8 justify-end rounded-md px-2 py-0.5 tabular-nums" :class="pillClass(cell.tone)" data-testid="month-cell">
                                    {{ cell.text }}
                                </span>
                            </td>
                            <td
                                v-if="entry.row.uncalculatedSpan > 0"
                                :colspan="entry.row.uncalculatedSpan"
                                class="truncate px-3 bg-[repeating-linear-gradient(135deg,var(--color-stone-50)_0_8px,var(--color-stone-100)_8px_16px)]"
                                :title="entry.row.sku.uncalculated_months_label ?? undefined"
                                data-testid="uncalculated-months"
                            >
                                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs text-stone-500 ring-1 ring-stone-200">計算できない：{{ entry.row.sku.uncalculated_months_label }}</span>
                            </td>
                        </template>
                        <td v-else :colspan="months.length" class="truncate px-3" :title="entry.row.sku.forecast_reason_label ?? entry.row.sku.status_label" data-testid="status">
                            <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-500">{{ entry.row.sku.status_label }}</span>
                        </td>
                    </tr>
                </template>
                <tr v-if="bottomSpacerHeight > 0" aria-hidden="true" :style="{ height: `${bottomSpacerHeight}px` }">
                    <td :colspan="months.length + 2" class="p-0" />
                </tr>
            </tbody>
        </table>

        <div
            v-if="tooltip.content"
            ref="tooltipElement"
            role="tooltip"
            class="pointer-events-none fixed z-50 w-56 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg bg-stone-900 text-left text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }"
        >
            <TrendTooltip :content="tooltip.content" :stock-date="stockDate" :demand-label="demandLabel" :stock-scope="stockScope" />
        </div>
    </div>
</template>
