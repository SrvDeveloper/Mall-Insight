<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from "vue";
import { fetchStoreRanking } from "@/api/actuals";
import { STORE_COLORS, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import { formatYen } from "@/components/salesTarget/format";
import type { SalesUnit, StoreRankingResponse, StoreSales, StoreSalesProducts } from "@/types/api";

/**
 * 店舗売上の月・日の欄を押したときに開く、売上上位の品番とSKU（各10件、K-091）のモーダル。店舗の行の欄ならその店舗、合計の行なら全店舗。
 * 金額と販売数のどちらの多い順かを切り替えられる（初めは店舗売上で見ている方）。各行に、その店舗・期間の全体に対する割合を棒と数字で出す。
 * CrossWalkerに未登録のSKUは、SKUの順位には入れ、品番の順位には入れずに件数と合計を添える。Esc キー、×、外側を押すと閉じる。
 */

const props = defineProps<{
    /** 開いている欄。null なら閉じている */
    target: { period: string; label: string; mall: StoreSales["mall"] | null; storeLabel: string } | null;
    unit: SalesUnit;
    products: StoreSalesProducts;
    measure: ActualMeasure;
}>();
const emit = defineEmits<{ close: [] }>();

const ORDERS: { value: ActualMeasure; label: string }[] = [
    { value: "amount", label: "金額順" },
    { value: "quantity", label: "販売数順" },
];

const order = ref<ActualMeasure>(props.measure);
const result = shallowRef<StoreRankingResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

async function load(): Promise<void> {
    const target = props.target;
    if (!target) {
        return;
    }
    const requested = order.value;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchStoreRanking(props.unit, target.period, target.mall, props.products, requested);
        // 読み込み中に欄や並び順を変えたときは、古い方の結果を表示しない
        if (props.target === target && order.value === requested) {
            result.value = response;
        }
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        emit("close");
    }
}

watch(
    () => props.target,
    async (target) => {
        result.value = null;
        if (target) {
            order.value = props.measure;
            document.addEventListener("keydown", onKeydown);
            void load();
            await nextTick();
            closeButton.value?.focus();
        } else {
            document.removeEventListener("keydown", onKeydown);
        }
    },
    { immediate: true },
);
watch(order, () => void load());
onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

const valueOf = (row: { quantity: number; amount: number }): number => (order.value === "amount" ? row.amount : row.quantity);
const format = (value: number): string => (order.value === "amount" ? formatYen(value) : `${formatQuantity(value)}個`);
const totalValue = computed(() => (result.value ? valueOf(result.value.meta.total) : 0));
const share = (row: { quantity: number; amount: number }): number => (totalValue.value > 0 ? valueOf(row) / totalValue.value : 0);
const formatShare = (value: number): string => `${(value * 100).toFixed(1)}%`;
const color = computed(() => (props.target?.mall ? STORE_COLORS[props.target.mall] : "#57534e"));
/** 売れ方が分かるよう、金額順なら販売数、販売数順なら金額を小さく添える。 */
const subOf = (row: { quantity: number; amount: number }): string => (order.value === "amount" ? `${formatQuantity(row.quantity)}個` : formatYen(row.amount));
</script>

<template>
    <div v-if="target" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-stone-900/45 p-4 sm:p-10" @click.self="emit('close')">
        <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="ranking-heading"
            class="w-full max-w-5xl overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
            data-testid="ranking-dialog"
        >
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div class="flex flex-col gap-1">
                    <h2 id="ranking-heading" class="flex items-center gap-2 text-base font-bold text-stone-900">
                        <span class="size-3 rounded-sm" :style="{ backgroundColor: color }" aria-hidden="true" />
                        {{ target.storeLabel }}・{{ target.label }}の売上上位
                    </h2>
                    <p class="text-xs text-stone-500 tabular-nums" data-testid="ranking-summary">
                        <template v-if="result">
                            {{ result.meta.from.replaceAll("-", "/") }}{{ result.meta.from === result.meta.to ? "" : `〜${result.meta.to.replaceAll("-", "/")}` }}・{{
                                products === "all" ? "全商品" : "対象品番"
                            }}・合計 {{ formatYen(result.meta.total.amount) }}（{{ formatQuantity(result.meta.total.quantity) }}個）
                        </template>
                        <template v-else>読み込み中…</template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="並び順">
                        <button
                            v-for="option in ORDERS"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="order === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                            :aria-pressed="order === option.value"
                            :data-testid="`ranking-order-${option.value}`"
                            @click="order = option.value"
                        >
                            {{ option.label }}
                        </button>
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
                </div>
            </header>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <p class="text-sm text-stone-600">売上上位を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>
            <div v-else-if="!result" class="px-6 py-12 text-center text-sm text-stone-500">読み込み中…</div>
            <div v-else-if="result.meta.total.quantity === 0" class="px-6 py-12 text-center text-sm text-stone-500" data-testid="ranking-empty">この期間の販売実績はありません。</div>
            <div v-else class="grid gap-0 md:grid-cols-2" :aria-busy="isLoading">
                <section class="flex min-w-0 flex-col border-stone-200 max-md:border-b md:border-r" aria-labelledby="ranking-items">
                    <h3 id="ranking-items" class="px-5 pt-4 pb-2 text-sm font-semibold text-stone-900">品番 上位{{ result.data.items.length }}</h3>
                    <ol class="flex flex-col pb-2" data-testid="ranking-items">
                        <li v-for="(row, index) in result.data.items" :key="row.item_no" class="flex items-center gap-3 px-5 py-2" data-testid="ranking-item">
                            <span class="w-5 shrink-0 text-right text-[11px] text-stone-400 tabular-nums">{{ index + 1 }}</span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ row.item_no }}</span>
                                    <span class="min-w-0 truncate rounded-full border border-stone-200 px-2 py-px text-[11px] text-stone-600">{{ row.brand }}・{{ row.category }}</span>
                                    <span class="shrink-0 text-[11px] text-stone-400 tabular-nums">{{ row.sku_count }} SKU</span>
                                </span>
                                <span class="h-1 w-full rounded-full bg-stone-100"><span class="block h-full rounded-full" :style="{ width: `${share(row) * 100}%`, backgroundColor: color }" /></span>
                            </span>
                            <span class="flex shrink-0 flex-col items-end tabular-nums">
                                <span class="text-sm text-stone-900">{{ format(valueOf(row)) }}</span>
                                <span class="text-[11px] text-stone-500">{{ formatShare(share(row)) }}・{{ subOf(row) }}</span>
                            </span>
                        </li>
                    </ol>
                    <p v-if="result.data.items.length === 0" class="px-5 pb-4 text-xs text-stone-500">品番に登録されたSKUの販売実績はありません。</p>
                    <p v-if="result.data.unregistered.sku_count > 0" class="mt-auto border-t border-stone-100 px-5 py-2.5 text-xs text-stone-500 tabular-nums" data-testid="ranking-unregistered">
                        CrossWalkerに未登録のSKU（{{ formatQuantity(result.data.unregistered.sku_count) }} SKU）：{{ format(valueOf(result.data.unregistered)) }}・{{
                            formatShare(share(result.data.unregistered))
                        }}。品番の順位には含めていません
                    </p>
                </section>
                <section class="flex min-w-0 flex-col" aria-labelledby="ranking-skus">
                    <h3 id="ranking-skus" class="px-5 pt-4 pb-2 text-sm font-semibold text-stone-900">SKU 上位{{ result.data.skus.length }}</h3>
                    <ol class="flex flex-col pb-2" data-testid="ranking-skus">
                        <li v-for="(row, index) in result.data.skus" :key="row.sku_id" class="flex items-center gap-3 px-5 py-2" data-testid="ranking-sku">
                            <span class="w-5 shrink-0 text-right text-[11px] text-stone-400 tabular-nums">{{ index + 1 }}</span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate font-mono text-sm text-stone-900" :title="row.sku_code">{{ row.sku_code }}</span>
                                    <span v-if="row.item_no === null" class="shrink-0 rounded-full bg-amber-50 px-1.5 py-px text-[10px] text-amber-800 ring-1 ring-amber-200">未登録</span>
                                </span>
                                <span class="h-1 w-full rounded-full bg-stone-100"><span class="block h-full rounded-full" :style="{ width: `${share(row) * 100}%`, backgroundColor: color }" /></span>
                            </span>
                            <span class="flex shrink-0 flex-col items-end tabular-nums">
                                <span class="text-sm text-stone-900">{{ format(valueOf(row)) }}</span>
                                <span class="text-[11px] text-stone-500">{{ formatShare(share(row)) }}・{{ subOf(row) }}</span>
                            </span>
                        </li>
                    </ol>
                </section>
            </div>
            <p class="border-t border-stone-200 px-5 py-2.5 text-[11px] text-stone-500">割合は、この店舗・期間の合計（{{ order === "amount" ? "金額" : "販売数" }}）に対する割合です。</p>
        </section>
    </div>
</template>
