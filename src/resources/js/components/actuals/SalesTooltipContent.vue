<script setup lang="ts">
import { computed } from "vue";
import type { SalesTooltip } from "@/components/actuals/salesTooltip";
import type { SalesUnit } from "@/types/api";

/**
 * 販売実績のツールチップの中身（グラフと店舗売上の表で共通、K-082・K-084）。合計と系列ごとの値・前月比（前日比）・前年比を表にし、
 * 販売実績を取り込んでいない系列があれば、合計に含めていないことを添える（原則2）。
 */

const props = defineProps<{ tooltip: SalesTooltip; formatValue: (value: number) => string; unit: SalesUnit }>();

const comparisonLabels = computed(() => (props.unit === "day" ? ["前日比", "前年比"] : ["前月比", "前年比"]));
const hasShare = computed(() => props.tooltip.rows.some((row) => row.share !== undefined));
const gridColumns = computed(() => (hasShare.value ? "grid-cols-[minmax(0,1fr)_auto_3rem_3.25rem_3.25rem]" : "grid-cols-[minmax(0,1fr)_auto_3.25rem_3.25rem]"));
</script>

<template>
    <div class="flex items-baseline justify-between gap-2 border-b border-white/10 pb-1">
        <span class="font-semibold text-white">{{ tooltip.column.label }}</span>
        <span class="text-[10px] text-stone-400">{{ tooltip.column.sub }}</span>
    </div>
    <template v-if="tooltip.total === null">
        <p class="pt-1 text-stone-300">販売実績を取り込んでいません</p>
    </template>
    <template v-else>
        <div class="grid items-center gap-x-2 gap-y-0.5 pt-1 tabular-nums" :class="gridColumns">
            <span />
            <span />
            <span v-if="hasShare" class="text-right text-[10px] text-stone-400">構成比</span>
            <span class="text-right text-[10px] text-stone-400">{{ comparisonLabels[0] }}</span>
            <span class="text-right text-[10px] text-stone-400">{{ comparisonLabels[1] }}</span>

            <span class="text-stone-300">合計</span>
            <span class="text-right font-semibold text-white" data-testid="tooltip-total">{{ formatValue(tooltip.total) }}</span>
            <span v-if="hasShare" class="text-right text-stone-500">100%</span>
            <span class="text-right" :class="tooltip.previous === '—' ? 'text-stone-500' : 'text-white'" data-testid="tooltip-total-previous">{{ tooltip.previous }}</span>
            <span class="text-right" :class="tooltip.lastYear === '—' ? 'text-stone-500' : 'text-white'" data-testid="tooltip-total-last-year">{{ tooltip.lastYear }}</span>

            <template v-for="row in tooltip.rows" :key="row.key">
                <span class="flex min-w-0 items-center gap-1.5 text-stone-300"
                    ><span class="h-2 w-2 shrink-0 rounded-sm" :style="{ backgroundColor: row.color }" /><span class="truncate">{{ row.label }}</span></span
                >
                <span class="text-right" :data-testid="`tooltip-value-${row.key}`">{{ formatValue(row.value!) }}</span>
                <span v-if="hasShare" class="text-right text-stone-300" :data-testid="`tooltip-share-${row.key}`">{{ row.share }}</span>
                <span class="text-right" :class="row.previous === '—' ? 'text-stone-500' : ''" :data-testid="`tooltip-previous-${row.key}`">{{ row.previous }}</span>
                <span class="text-right" :class="row.lastYear === '—' ? 'text-stone-500' : ''" :data-testid="`tooltip-last-year-${row.key}`">{{ row.lastYear }}</span>
            </template>
        </div>
        <p v-if="tooltip.missing.length" class="mt-1 border-t border-white/10 pt-1 text-[10px] leading-snug text-stone-400" data-testid="tooltip-missing">
            {{ tooltip.missing.join("・") }}は販売実績を取り込んでいないため、合計に含めていません
        </p>
        <p v-if="tooltip.column.current && unit === 'month'" class="mt-1 border-t border-white/10 pt-1 text-[10px] leading-snug text-stone-400" data-testid="tooltip-note">
            今月は、前月・前年の同じ月の同じ日までと比べています
        </p>
    </template>
    <div v-if="tooltip.stockouts.length" class="mt-1 border-t border-white/10 pt-1" data-testid="tooltip-stockouts">
        <p class="text-[10px] text-stone-400">欠品日数{{ tooltip.column.current ? "（今日まで）" : "" }}</p>
        <div v-for="row in tooltip.stockouts" :key="row.key" class="flex justify-between gap-2 tabular-nums">
            <span class="text-stone-300">{{ row.label }}</span>
            <span :class="row.value === '—' ? 'text-stone-500' : 'text-white'" :data-testid="`tooltip-stockouts-${row.key}`">{{ row.value }}</span>
        </div>
        <p v-if="tooltip.stockouts.some((row) => row.value === '—')" class="text-[10px] text-stone-400">—：判定できる在庫記録なし</p>
    </div>
</template>
