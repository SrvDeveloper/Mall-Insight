<script setup lang="ts">
import { nextTick, onBeforeUnmount, useTemplateRef, watch } from "vue";
import MallShareBar from "@/components/actuals/MallShareBar.vue";
import { MALL_SERIES, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatShare, formatTotal, type ColorSizeMatrix, type Mall, type SkuSalesRow } from "@/components/actuals/skuSales";

/**
 * SKU別売上のカラー×度数の表（モーダル、K-081）。縦がカラー、横が度数で、各欄に期間計・割合・期間の推移（BOSS・Amazonの2本）・
 * 2色の棒を出し、カラー計と度数計も付ける。欄を押すとそのSKUを選んで閉じる。Esc キー、×、外側を押しても閉じる。
 */

const props = defineProps<{ open: boolean; itemNo: string; matrix: ColorSizeMatrix; total: number; top: number; measure: ActualMeasure; selectedSkuId: number | null }>();
const emit = defineEmits<{ close: []; select: [skuId: number] }>();

const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        emit("close");
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            document.addEventListener("keydown", onKeydown);
            await nextTick();
            closeButton.value?.focus();
        } else {
            document.removeEventListener("keydown", onKeydown);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

const cell = (color: string, size: string): SkuSalesRow | undefined => props.matrix.cells[`${color}|${size}`];
const sumOf = (sums: Record<Mall, number> | undefined): number => (sums ? sums.boss + sums.amazon : 0);

/** 欄の小さな推移（BOSS・Amazonの2本、欄ごとの目盛り）。 */
function line(row: SkuSalesRow, mall: Mall): string {
    const values = row.malls[mall];
    const max = Math.max(1, ...row.malls.boss.map((value) => value ?? 0), ...row.malls.amazon.map((value) => value ?? 0));
    const step = 156 / Math.max(1, values.length - 1);
    return values
        .map((value, index) => (value === null ? null : `${(2 + index * step).toFixed(1)} ${(32 - (value / max) * 29).toFixed(1)}`))
        .filter((point): point is string => point !== null)
        .map((point, index) => `${index === 0 ? "M" : "L"} ${point}`)
        .join(" ");
}

const [BOSS, AMAZON] = MALL_SERIES;
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-stone-900/45 p-4 sm:p-10" @click.self="emit('close')">
        <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="matrix-heading"
            class="w-full max-w-6xl overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
            data-testid="matrix-dialog"
        >
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div class="flex flex-col gap-1">
                    <h2 id="matrix-heading" class="text-base font-bold text-stone-900">
                        <span class="font-mono font-medium">{{ itemNo }}</span> のカラー × 度数
                    </h2>
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500">
                        期間計・期間の推移・モールの割合。欄を押すと、そのSKUの詳細に移ります。
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: BOSS!.color }" />BOSS</span>
                        <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: AMAZON!.color }" />Amazon</span>
                    </p>
                </div>
                <button
                    ref="closeButton"
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="閉じる"
                    @click="emit('close')"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
                </button>
            </header>
            <div class="overflow-x-auto">
                <div class="grid min-w-[60rem] tabular-nums" :style="{ gridTemplateColumns: `6rem repeat(${matrix.sizes.length}, minmax(0, 1fr)) 7.5rem` }">
                    <div class="border-b border-stone-300 px-4 py-2.5 text-xs text-stone-500">カラー＼度数</div>
                    <div v-for="size in matrix.sizes" :key="size.size" class="border-b border-l border-stone-300 border-l-stone-100 px-4 py-2.5 text-center text-[13px] font-medium text-stone-800">
                        {{ size.label }}
                    </div>
                    <div class="border-b border-l border-stone-300 border-l-stone-200 bg-stone-50 px-4 py-2.5 text-right text-xs text-stone-500">カラー計</div>

                    <template v-for="color in matrix.colors" :key="color">
                        <div class="flex items-center border-b border-stone-100 px-4 text-[13px] font-medium text-stone-800">カラー {{ color }}</div>
                        <template v-for="size in matrix.sizes" :key="`${color}-${size.size}`">
                            <button
                                v-if="cell(color, size.size)"
                                type="button"
                                class="flex flex-col gap-1.5 border-b border-l border-stone-100 px-3.5 py-3 text-left hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900 focus-visible:ring-inset"
                                :class="selectedSkuId === cell(color, size.size)!.sku.sku_id ? 'bg-stone-100 shadow-[inset_0_0_0_2px_var(--color-stone-900)]' : ''"
                                :aria-label="cell(color, size.size)!.sku.sku_code"
                                :aria-pressed="selectedSkuId === cell(color, size.size)!.sku.sku_id"
                                :data-testid="`cell-${color}-${size.size}`"
                                @click="emit('select', cell(color, size.size)!.sku.sku_id)"
                            >
                                <template v-if="!cell(color, size.size)!.sku.not_sold">
                                    <span class="flex items-baseline justify-between gap-1">
                                        <span class="text-lg font-bold text-stone-900">{{ formatTotal(cell(color, size.size)!.sum, measure) }}</span>
                                        <span class="text-[11px] text-stone-500">{{ formatShare(cell(color, size.size)!.share) }}</span>
                                    </span>
                                    <svg width="100%" height="34" viewBox="0 0 160 34" preserveAspectRatio="none" aria-hidden="true">
                                        <line x1="0" x2="160" y1="32" y2="32" class="stroke-stone-200" stroke-width="1" vector-effect="non-scaling-stroke" />
                                        <path
                                            :d="line(cell(color, size.size)!, 'boss')"
                                            fill="none"
                                            :stroke="BOSS!.color"
                                            stroke-width="1.5"
                                            stroke-linejoin="round"
                                            vector-effect="non-scaling-stroke"
                                        />
                                        <path
                                            :d="line(cell(color, size.size)!, 'amazon')"
                                            fill="none"
                                            :stroke="AMAZON!.color"
                                            stroke-width="1.5"
                                            stroke-linejoin="round"
                                            vector-effect="non-scaling-stroke"
                                        />
                                    </svg>
                                    <MallShareBar :boss="cell(color, size.size)!.mallSums.boss / Math.max(1, top)" :amazon="cell(color, size.size)!.mallSums.amazon / Math.max(1, top)" />
                                    <span class="text-[11px] text-stone-500"
                                        >BOSS {{ formatTotal(cell(color, size.size)!.mallSums.boss, measure) }}・Amazon {{ formatTotal(cell(color, size.size)!.mallSums.amazon, measure) }}</span
                                    >
                                </template>
                                <span v-else class="text-xs text-stone-400">販売していません</span>
                            </button>
                            <div v-else class="flex items-center justify-center border-b border-l border-stone-100 text-stone-300">—</div>
                        </template>
                        <div class="flex flex-col justify-center gap-1 border-b border-l border-stone-100 border-l-stone-200 bg-stone-50 px-4 py-3 text-right">
                            <span class="text-base font-bold text-stone-900">{{ formatTotal(sumOf(matrix.colorSums[color]), measure) }}</span>
                            <span class="text-[11px] text-stone-500">{{ formatShare(total > 0 ? sumOf(matrix.colorSums[color]) / total : 0) }}</span>
                            <MallShareBar
                                :boss="sumOf(matrix.colorSums[color]) > 0 ? matrix.colorSums[color]!.boss / sumOf(matrix.colorSums[color]) : 0"
                                :amazon="sumOf(matrix.colorSums[color]) > 0 ? matrix.colorSums[color]!.amazon / sumOf(matrix.colorSums[color]) : 0"
                            />
                        </div>
                    </template>

                    <div class="flex items-center bg-stone-50 px-4 py-3 text-xs text-stone-500">度数計</div>
                    <div v-for="size in matrix.sizes" :key="`sum-${size.size}`" class="flex flex-col gap-1 border-l border-stone-100 bg-stone-50 px-4 py-3" :data-testid="`size-sum-${size.size}`">
                        <span class="flex items-baseline justify-between gap-1"
                            ><span class="text-base font-bold text-stone-900">{{ formatTotal(sumOf(matrix.sizeSums[size.size]), measure) }}</span
                            ><span class="text-[11px] text-stone-500">{{ formatShare(total > 0 ? sumOf(matrix.sizeSums[size.size]) / total : 0) }}</span></span
                        >
                        <MallShareBar
                            :boss="sumOf(matrix.sizeSums[size.size]) > 0 ? matrix.sizeSums[size.size]!.boss / sumOf(matrix.sizeSums[size.size]) : 0"
                            :amazon="sumOf(matrix.sizeSums[size.size]) > 0 ? matrix.sizeSums[size.size]!.amazon / sumOf(matrix.sizeSums[size.size]) : 0"
                        />
                    </div>
                    <div class="flex items-center justify-end border-l border-stone-200 bg-stone-100 px-4 py-3 text-base font-bold text-stone-900">{{ formatTotal(total, measure) }}</div>
                </div>
            </div>
        </section>
    </div>
</template>
