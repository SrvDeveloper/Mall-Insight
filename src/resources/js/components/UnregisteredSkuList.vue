<script setup lang="ts">
import { onMounted, ref } from "vue";
import { fetchUnregisteredSkus } from "@/api/unregisteredSkus";
import type { UnregisteredSkuPage } from "@/types/api";

/**
 * CrossWalker に未登録のSKU（バックログ B-010）。販売実績か在庫に出てくるのに、どの品番にも属していないSKUを、
 * 直近12か月の販売数量が多い順に表示する（決定記録 K-029）。担当者はこれを見て CrossWalker に手で登録する（K-025）。
 */

const emit = defineEmits<{ loaded: [total: number] }>();

const result = ref<UnregisteredSkuPage | null>(null);
const isLoading = ref(false);
const hasError = ref(false);

let latestRequest = 0;

async function load(page = 1): Promise<void> {
    const requestId = ++latestRequest;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchUnregisteredSkus(page);
        if (requestId === latestRequest) {
            result.value = response;
            emit("loaded", response.meta.total);
        }
    } catch {
        if (requestId === latestRequest) {
            hasError.value = true;
        }
    } finally {
        if (requestId === latestRequest) {
            isLoading.value = false;
        }
    }
}

onMounted(() => load());

function formatDate(value: string): string {
    return value.replaceAll("-", "/");
}

function formatRange(first: string | null, last: string | null): string | null {
    if (!first || !last) {
        return null;
    }
    return first === last ? formatDate(first) : `${formatDate(first)} 〜 ${formatDate(last)}`;
}
</script>

<template>
    <!-- 品番・SKUの画面のカードの中に表示するため、枠は付けない -->
    <section :aria-busy="isLoading">
        <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p class="text-sm text-stone-600">未登録のSKUを表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load()">再読み込み</button>
        </div>

        <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="result.meta.total === 0" class="px-6 py-16 text-center text-sm text-stone-500">未登録のSKUはありません。</div>

        <template v-else>
            <div class="flex flex-col gap-1 border-b border-stone-200 px-4 py-3.5 text-[13px] text-stone-600">
                <p>販売実績か在庫に出てくるのに、CrossWalker のどの品番にも属していないSKUです。CrossWalker に登録すると、次の取得で品番に属し、この一覧から消えます。</p>
                <p class="text-xs text-stone-500 tabular-nums">販売数の集計期間：{{ formatDate(result.meta.sales_from) }} 〜 {{ formatDate(result.meta.sales_to) }}（直近12か月）</p>
            </div>

            <div class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">SKUコード</th>
                            <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">直近12か月の販売数</th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">販売実績に出てきた日</th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">在庫に出てきた日</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sku in result.data" :key="sku.id" class="h-11 hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100">
                            <td class="px-4 font-mono text-[12.5px] font-medium whitespace-nowrap text-stone-900">{{ sku.sku_code }}</td>
                            <td class="px-3 text-right text-stone-900 tabular-nums">{{ sku.recent_sales_quantity.toLocaleString() }}</td>
                            <td class="px-3 whitespace-nowrap tabular-nums">
                                <span v-if="formatRange(sku.first_sold_on, sku.last_sold_on)" class="text-stone-700">{{ formatRange(sku.first_sold_on, sku.last_sold_on) }}</span>
                                <span v-else class="text-stone-400">なし</span>
                            </td>
                            <td class="px-3 whitespace-nowrap tabular-nums">
                                <span v-if="formatRange(sku.first_stocked_on, sku.last_stocked_on)" class="text-stone-700">{{ formatRange(sku.first_stocked_on, sku.last_stocked_on) }}</span>
                                <span v-else class="text-stone-400">なし</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-[13px] text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1 || isLoading"
                        @click="load(result.meta.current_page - 1)"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page >= result.meta.last_page || isLoading"
                        @click="load(result.meta.current_page + 1)"
                    >
                        次へ
                    </button>
                </div>
            </footer>
        </template>
    </section>
</template>
