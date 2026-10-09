<script setup lang="ts">
import MallShareBar from "@/components/actuals/MallShareBar.vue";
import type { ActualMeasure } from "@/components/actuals/actualRows";
import { formatShare, formatTotal, type SkuSalesRow } from "@/components/actuals/skuSales";

/**
 * SKU別売上の左の一覧（K-081）。SKUを期間計の多い順に並べ、2色の棒（長さ＝多さ、色＝モールの割合）、期間の小さな推移、期間計と割合を出す。
 * 行を押すとそのSKUを選ぶ。見出しの「カラー×度数で見る」で表をモーダルで開く。度数の無い品番ではボタンを押せない。
 */

defineProps<{ rows: SkuSalesRow[]; selectedSkuId: number | null; measure: ActualMeasure; hasMatrix: boolean; notSoldLabel: string }>();
const emit = defineEmits<{ select: [skuId: number]; openMatrix: [] }>();

/** 期間の小さな推移（合計の1本）。 */
function spark(values: (number | null)[]): string {
    const max = Math.max(1, ...values.map((value) => value ?? 0));
    const step = 80 / Math.max(1, values.length - 1);
    return values
        .map((value, index) => (value === null ? null : `${(2 + index * step).toFixed(1)} ${(24 - ((value ?? 0) / max) * 22).toFixed(1)}`))
        .filter((point): point is string => point !== null)
        .map((point, index) => `${index === 0 ? "M" : "L"} ${point}`)
        .join(" ");
}
</script>

<template>
    <section aria-label="SKUの一覧" class="overflow-hidden rounded-xl border border-stone-200 bg-white" data-testid="sku-list">
        <div class="flex items-center justify-between gap-2 border-b border-stone-200 py-2.5 pr-3 pl-4">
            <h2 class="text-sm font-bold text-stone-900">SKU <span class="text-xs font-normal text-stone-500">（期間計の多い順）</span></h2>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-[13px] font-medium"
                :class="hasMatrix ? 'border-stone-300 text-stone-900 hover:bg-stone-50' : 'cursor-not-allowed border-stone-200 bg-stone-50 text-stone-400'"
                :disabled="!hasMatrix"
                :title="hasMatrix ? 'カラーと度数の表で見る' : 'この品番には度数がありません'"
                data-testid="open-matrix"
                @click="emit('openMatrix')"
            >
                <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="2" y="2" width="12" height="12" rx="1.5" />
                    <path d="M2 6.5h12M2 10.5h12M6 2v12M10 2v12" />
                </svg>
                カラー×度数で見る
            </button>
        </div>
        <ol class="max-h-[44rem] overflow-y-auto">
            <li v-for="row in rows" :key="row.sku.sku_id" class="border-t border-stone-100 first:border-t-0">
                <button
                    type="button"
                    class="grid w-full grid-cols-[1.25rem_1fr_5.25rem_4.25rem] items-center gap-2.5 border-l-[3px] py-1.5 pr-4 pl-3 text-left"
                    :class="selectedSkuId === row.sku.sku_id ? 'border-stone-900 bg-stone-100' : 'border-transparent hover:bg-stone-50'"
                    :aria-pressed="selectedSkuId === row.sku.sku_id"
                    :data-testid="`sku-${row.sku.sku_id}`"
                    @click="emit('select', row.sku.sku_id)"
                >
                    <span class="text-right text-[11px] text-stone-400 tabular-nums">{{ row.rank ?? "" }}</span>
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate font-mono text-[13px] text-stone-900" :class="selectedSkuId === row.sku.sku_id ? 'font-medium' : ''">{{ row.sku.sku_code }}</span>
                            <span v-if="row.sku.status === 'inactive'" class="shrink-0 rounded-full bg-stone-100 px-1.5 text-[10px] text-stone-500">{{ row.sku.status_label }}</span>
                        </span>
                        <MallShareBar v-if="!row.sku.not_sold" :boss="row.bars.boss" :amazon="row.bars.amazon" />
                        <span v-else class="text-[11px] text-stone-400">{{ notSoldLabel }}</span>
                    </span>
                    <svg width="84" height="26" viewBox="0 0 84 26" aria-hidden="true">
                        <path
                            v-if="!row.sku.not_sold"
                            :d="spark(row.values)"
                            fill="none"
                            :class="selectedSkuId === row.sku.sku_id ? 'stroke-stone-900' : 'stroke-stone-400'"
                            stroke-width="1.5"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <span class="flex flex-col items-end tabular-nums">
                        <span class="text-sm text-stone-900" :class="selectedSkuId === row.sku.sku_id ? 'font-bold' : ''" data-testid="sku-sum">{{
                            row.sku.not_sold ? "—" : formatTotal(row.sum, measure)
                        }}</span>
                        <span class="text-[11px] text-stone-500">{{ row.sku.not_sold ? "" : formatShare(row.share) }}</span>
                    </span>
                </button>
            </li>
        </ol>
    </section>
</template>
