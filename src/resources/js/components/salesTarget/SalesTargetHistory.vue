<script setup lang="ts">
import { onMounted, ref } from "vue";
import { fetchSalesTargetHistory } from "@/api/salesTargets";
import { formatDateTime, formatQuantity } from "@/components/salesTarget/format";
import type { SalesTarget } from "@/types/api";

/**
 * 品番の年間販売目標の変更履歴（新しい順、原則3）。変更のたびに記録した版を並べる。
 */

const props = defineProps<{ fiscalYear: number; itemNo: string }>();

const versions = ref<SalesTarget[] | null>(null);
const hasError = ref(false);

onMounted(async () => {
    try {
        versions.value = await fetchSalesTargetHistory(props.fiscalYear, props.itemNo);
    } catch {
        hasError.value = true;
    }
});

const monthCount = (version: SalesTarget): number => version.monthly_quantities.filter((value) => value !== null).length;
const skuCount = (version: SalesTarget): number => Object.keys(version.sku_quantities).length;
</script>

<template>
    <p v-if="hasError" class="px-5 py-10 text-center text-sm text-stone-600">履歴を表示できませんでした。</p>
    <p v-else-if="!versions" class="px-5 py-10 text-center text-sm text-stone-500">読み込み中…</p>
    <div v-else class="overflow-x-auto">
        <table class="w-full border-separate border-spacing-0 text-left text-[13px]" data-testid="target-history">
            <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                <tr>
                    <th scope="col" class="px-5 py-2.5 whitespace-nowrap">変更日時</th>
                    <th scope="col" class="px-3 py-2.5 whitespace-nowrap">変更した人</th>
                    <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">年間</th>
                    <th scope="col" class="px-3 py-2.5 whitespace-nowrap">手で直したところ</th>
                    <th scope="col" class="px-5 py-2.5">メモ</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(version, index) in versions" :key="version.id" class="h-11 [&>*]:border-t [&>*]:border-stone-100">
                    <td class="px-5 whitespace-nowrap text-stone-900 tabular-nums">
                        {{ formatDateTime(version.changed_at) }}
                        <span v-if="index === 0" class="ml-2 rounded-full bg-stone-900 px-2 py-0.5 text-[11px] font-semibold text-white">今の目標</span>
                    </td>
                    <td class="px-3 whitespace-nowrap">
                        <span v-if="version.changed_by" class="text-stone-700">{{ version.changed_by }}</span>
                        <span v-else class="text-stone-400">{{ version.source === "excel" ? version.source_label : "記録なし" }}</span>
                    </td>
                    <td class="px-3 text-right font-semibold text-stone-900 tabular-nums">{{ formatQuantity(version.annual_quantity) }}</td>
                    <td class="px-3 whitespace-nowrap text-stone-600 tabular-nums">
                        <template v-if="monthCount(version) === 0 && skuCount(version) === 0">なし</template>
                        <template v-else>月 {{ monthCount(version) }}か月・SKU {{ skuCount(version) }}件</template>
                    </td>
                    <td class="px-5 text-stone-600">
                        <span v-if="version.note">{{ version.note }}</span>
                        <span v-else class="text-stone-300">—</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
