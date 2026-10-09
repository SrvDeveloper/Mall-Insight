<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, type RouteLocationRaw } from "vue-router";
import SalesChart from "@/components/actuals/SalesChart.vue";
import { chartFormats, mallSeries, type ActualColumn, type ActualItemRows, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";

/**
 * 品番別売上のグラフ（B-127、K-079）。左に品番の一覧（期間計の多い順、全体に対する割合付き）、右に選んだ品番の大きな棒グラフを出す。
 * 棒はモールごとの積み上げ（全体なら BOSS・Amazon、モールを選んだらそのモールだけ）。一覧で品番を押すと右のグラフを切り替え、
 * 「SKU別売上を見る」でその品番のSKU別売上へ移る。選んだ品番は呼び出し側が URL に持たせる。
 */

const props = defineProps<{
    items: ActualItemRows[];
    columns: ActualColumn[];
    measure: ActualMeasure;
    summaryLabel: string;
    selectedItemNo: string | null;
    skuSalesLink: (itemNo: string) => RouteLocationRaw;
}>();
const emit = defineEmits<{ select: [itemNo: string] }>();

const sum = (values: (number | null)[]): number => values.reduce<number>((total, value) => total + (value ?? 0), 0);

/** 期間計の多い順の品番と、全体に対する割合。 */
const ranking = computed(() => {
    const rows = props.items.map((group) => ({ group, total: sum(group.totalValues) }));
    const all = rows.reduce((total, row) => total + row.total, 0);
    return rows.sort((a, b) => b.total - a.total).map((row) => ({ ...row, share: all > 0 ? row.total / all : 0 }));
});

const selected = computed(() => ranking.value.find((row) => row.group.item.item_no === props.selectedItemNo) ?? ranking.value[0] ?? null);
const series = computed(() => (selected.value ? mallSeries(selected.value.group.item, props.measure, props.columns.length) : []));
const formats = computed(() => chartFormats(props.measure));
</script>

<template>
    <div class="grid gap-0 lg:grid-cols-[18rem_1fr]" data-testid="item-sales-chart">
        <ol class="max-h-[34rem] overflow-y-auto border-stone-200 py-2 max-lg:border-b lg:border-r" aria-label="品番（期間計の多い順）">
            <li v-for="(row, index) in ranking" :key="row.group.item.item_no">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-stone-50"
                    :class="selected?.group.item.item_no === row.group.item.item_no ? 'bg-stone-100 hover:bg-stone-100' : ''"
                    :aria-pressed="selected?.group.item.item_no === row.group.item.item_no"
                    :data-testid="`rank-${row.group.item.item_no}`"
                    @click="emit('select', row.group.item.item_no)"
                >
                    <span class="w-5 shrink-0 text-right text-[11px] text-stone-400 tabular-nums">{{ index + 1 }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate font-mono text-sm font-medium text-stone-900">{{ row.group.item.item_no }}</span>
                        <!-- 全体に対する割合（細い棒） -->
                        <span class="mt-1 h-1 w-full rounded-full bg-stone-100"><span class="block h-full rounded-full bg-stone-400" :style="{ width: `${row.share * 100}%` }" /></span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end tabular-nums">
                        <span class="text-sm text-stone-900">{{ row.group.summaryText }}</span>
                        <span class="text-[11px] text-stone-500">{{ (row.share * 100).toFixed(1) }}%</span>
                    </span>
                </button>
            </li>
        </ol>

        <div v-if="selected" class="flex min-w-0 flex-col gap-3 p-4">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h3 class="font-mono text-lg font-medium text-stone-900" data-testid="chart-item-no">{{ selected.group.item.item_no }}</h3>
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ selected.group.item.brand }}・{{ selected.group.item.category }}</span>
                <span class="text-xs text-stone-500"
                    >{{ summaryLabel }} <strong class="text-sm text-stone-900 tabular-nums">{{ selected.group.summaryText }}</strong></span
                >
                <span class="text-xs text-stone-500 tabular-nums">（全体の {{ (selected.share * 100).toFixed(1) }}%・{{ formatQuantity(selected.group.rows.length) }} SKU）</span>
                <RouterLink
                    :to="skuSalesLink(selected.group.item.item_no)"
                    class="ml-auto inline-flex items-center gap-1 rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-medium text-stone-800 hover:bg-stone-50"
                    data-testid="chart-sku-link"
                >
                    SKU別売上を見る
                    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m6 3.5 4.5 4.5L6 12.5" />
                    </svg>
                </RouterLink>
            </div>
            <SalesChart :columns="columns" :series="series" :format-value="formats.formatValue" :format-axis="formats.formatAxis" :axis-unit="formats.axisUnit" />
        </div>
    </div>
</template>
