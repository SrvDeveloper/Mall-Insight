<script setup lang="ts">
import { computed } from "vue";
import MallShareBar from "@/components/actuals/MallShareBar.vue";
import SalesChart from "@/components/actuals/SalesChart.vue";
import { chartFormats, MALL_SERIES, skuMallSeries, type ActualColumn, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatCell, formatShare, formatTotal, type Mall, type SkuSalesRow } from "@/components/actuals/skuSales";
import type { SalesUnit } from "@/types/api";

/**
 * SKU別売上の右の詳細（K-081）。選んだSKUの期間計とモールの割合、BOSS・Amazon の折れ線、月（日）ごとの表（モールの割合の棒付き）を出す。
 * モールを選んでいるときは、そのモールだけを出す。
 */

const props = defineProps<{ row: SkuSalesRow; columns: ActualColumn[]; measure: ActualMeasure; unit: SalesUnit; malls: Mall[]; notSoldLabel: string }>();

const mallSeries = computed(() => MALL_SERIES.filter((mall) => props.malls.includes(mall.key)));
// 前月比・前年比のため、前の期間と前年の同じ期間の値も渡す（K-082）
const series = computed(() => skuMallSeries(props.row.sku, props.measure).filter((series) => props.malls.includes(series.key as Mall)));
const formats = computed(() => chartFormats(props.measure));
const mallShare = (mall: Mall): number => (props.row.sum > 0 ? props.row.mallSums[mall] / props.row.sum : 0);

/** 月（日）ごとの表の行。新しい期間を上にする。 */
const lines = computed(() =>
    props.columns
        .map((column, index) => {
            const values = mallSeries.value.map((mall) => props.row.malls[mall.key][index] ?? null);
            const total = values.every((value) => value === null) ? null : values.reduce<number>((sum, value) => sum + (value ?? 0), 0);
            return {
                column,
                values: values.map((value) => (value === null ? "—" : formatCell(value, props.measure))),
                total: total === null ? "—" : formatCell(total, props.measure),
                boss: total ? (props.row.malls.boss[index] ?? 0) / total : 0,
                amazon: total ? (props.row.malls.amazon[index] ?? 0) / total : 0,
            };
        })
        .reverse(),
);
</script>

<template>
    <section aria-label="選んだSKU" class="flex min-w-0 flex-col gap-4" data-testid="sku-detail">
        <div class="flex flex-col gap-3 rounded-xl border border-stone-200 bg-white px-5 pt-4 pb-3">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 class="font-mono text-lg font-medium text-stone-900" data-testid="detail-code">{{ row.sku.sku_code }}</h2>
                <span v-if="row.sku.status === 'inactive'" class="rounded-full bg-stone-100 px-2 text-[11px] text-stone-500">{{ row.sku.status_label }}</span>
                <span class="text-[13px] text-stone-600"
                    >期間計 <strong class="text-base text-stone-900 tabular-nums" data-testid="detail-sum">{{ formatTotal(row.sum, measure) }}</strong></span
                >
            </div>
            <p v-if="row.sku.not_sold" class="rounded-md bg-stone-50 px-3 py-10 text-center text-sm text-stone-500">{{ notSoldLabel }}</p>
            <template v-else>
                <div v-if="malls.length >= 2" class="flex flex-wrap items-center gap-3">
                    <span class="w-64"><MallShareBar :boss="mallShare('boss')" :amazon="mallShare('amazon')" :height="10" /></span>
                    <span class="text-xs text-stone-600 tabular-nums" data-testid="detail-malls"
                        >BOSS {{ formatTotal(row.mallSums.boss, measure) }}（{{ formatShare(mallShare("boss")) }}）・Amazon {{ formatTotal(row.mallSums.amazon, measure) }}（{{
                            formatShare(mallShare("amazon"))
                        }}）</span
                    >
                </div>
                <SalesChart kind="line" :columns="columns" :series="series" v-bind="formats" :unit="unit" :height="280" />
            </template>
        </div>

        <div v-if="!row.sku.not_sold" class="max-h-[28rem] overflow-auto rounded-xl border border-stone-200 bg-white">
            <table class="w-full border-collapse text-[13px] tabular-nums" data-testid="detail-table">
                <thead class="sticky top-0 bg-white text-xs text-stone-500">
                    <tr class="text-right [&>th]:border-b [&>th]:border-stone-300 [&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                        <th scope="col" class="text-left">{{ unit === "day" ? "日" : "月" }}</th>
                        <th v-for="mall in mallSeries" :key="mall.key" scope="col">{{ mall.label }}</th>
                        <th v-if="mallSeries.length >= 2" scope="col">合計</th>
                        <th v-if="mallSeries.length >= 2" scope="col" class="text-left">モールの割合</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in lines" :key="line.column.key" class="text-right [&>*]:border-t [&>*]:border-stone-100 [&>*]:px-4 [&>*]:py-1.5">
                        <th scope="row" class="text-left font-normal text-stone-600">
                            {{ line.column.label }}<span v-if="line.column.sub" class="ml-1.5 text-[11px] text-stone-400">{{ line.column.sub }}</span>
                        </th>
                        <td v-for="(value, index) in line.values" :key="index" :class="value === '—' ? 'text-stone-300' : 'text-stone-700'">{{ value }}</td>
                        <td v-if="mallSeries.length >= 2" class="font-semibold text-stone-900">{{ line.total }}</td>
                        <td v-if="mallSeries.length >= 2" class="w-36 text-left"><MallShareBar :boss="line.boss" :amazon="line.amazon" track /></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
