<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { ApiError } from "@/api/client";
import { carryOverSalesTargets, fetchCarryOverCandidates } from "@/api/salesTargets";
import { formatQuantity } from "@/components/salesTarget/format";
import type { CarryOverCandidate } from "@/types/api";

/**
 * 年間販売目標を前年度から引き継ぐ（バックログ B-110、決定記録 K-060）。引き継ぐ品番と、写す内容を選ぶ。
 * 年間の数は必ず写す。月の手直しとSKUの上書きは、選んだときだけ写す。この年度にすでに目標のある品番は、はじめは選ばない。
 */

const props = defineProps<{ fiscalYear: number }>();
const emit = defineEmits<{ saved: [count: number]; cancel: [] }>();

const candidates = ref<CarryOverCandidate[] | null>(null);
const hasError = ref(false);
const selected = ref(new Set<string>());
const includeMonthly = ref(false);
const includeSkus = ref(true);
const isSaving = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        candidates.value = await fetchCarryOverCandidates(props.fiscalYear);
        selected.value = new Set(candidates.value.filter((candidate) => candidate.current_annual_quantity === null).map((candidate) => candidate.item_no));
    } catch {
        hasError.value = true;
    }
});

const allSelected = computed(() => (candidates.value?.length ?? 0) > 0 && selected.value.size === candidates.value!.length);
const overwriteCount = computed(() => (candidates.value ?? []).filter((candidate) => selected.value.has(candidate.item_no) && candidate.current_annual_quantity !== null).length);

function toggle(itemNo: string): void {
    const next = new Set(selected.value);
    if (next.has(itemNo)) {
        next.delete(itemNo);
    } else {
        next.add(itemNo);
    }
    selected.value = next;
}

function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set((candidates.value ?? []).map((candidate) => candidate.item_no));
}

async function submit(): Promise<void> {
    if (isSaving.value || selected.value.size === 0) {
        return;
    }
    isSaving.value = true;
    error.value = null;
    try {
        const count = await carryOverSalesTargets(
            props.fiscalYear,
            (candidates.value ?? []).filter((candidate) => selected.value.has(candidate.item_no)).map((candidate) => candidate.item_no),
            includeMonthly.value,
            includeSkus.value,
        );
        emit("saved", count);
    } catch (caught) {
        if (caught instanceof ApiError && caught.isValidationError) {
            error.value = Object.values(caught.errors).flat()[0] ?? caught.message;
        }
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <p v-if="hasError" class="px-5 py-10 text-center text-sm text-stone-600">引き継げる目標を表示できませんでした。</p>
    <p v-else-if="!candidates" class="px-5 py-10 text-center text-sm text-stone-500">読み込み中…</p>
    <div v-else-if="candidates.length === 0" class="flex flex-col items-center gap-3 px-5 py-10 text-center text-sm text-stone-500">
        {{ fiscalYear - 1 }}年度の目標が無いため、引き継げません。
        <button type="button" class="h-9 rounded-lg border border-stone-300 px-3 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">閉じる</button>
    </div>
    <form v-else class="flex flex-col gap-5 px-5 py-5" @submit.prevent="submit">
        <fieldset class="flex flex-col gap-2">
            <legend class="mb-2 text-xs font-medium text-stone-700">写す内容</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                <label class="flex items-start gap-2.5 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2.5">
                    <input type="checkbox" class="mt-0.5 size-4 accent-stone-900" checked disabled />
                    <span class="flex flex-col"><span class="text-[13px] font-medium text-stone-900">年間の数</span><span class="text-[11px] text-stone-500">必ず写します</span></span>
                </label>
                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 hover:bg-stone-50" :class="includeMonthly ? 'border-stone-900' : 'border-stone-200'">
                    <input v-model="includeMonthly" type="checkbox" class="mt-0.5 size-4 accent-stone-900" data-testid="include-monthly" />
                    <span class="flex flex-col"
                        ><span class="text-[13px] font-medium text-stone-900">月の手直し</span><span class="text-[11px] text-stone-500">写さなければ月別販売比率どおりに分けます</span></span
                    >
                </label>
                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 hover:bg-stone-50" :class="includeSkus ? 'border-stone-900' : 'border-stone-200'">
                    <input v-model="includeSkus" type="checkbox" class="mt-0.5 size-4 accent-stone-900" data-testid="include-skus" />
                    <span class="flex flex-col"
                        ><span class="text-[13px] font-medium text-stone-900">SKUの上書き</span><span class="text-[11px] text-stone-500">写さなければ構成比で割り振ります</span></span
                    >
                </label>
            </div>
        </fieldset>

        <div class="flex flex-col gap-2">
            <div class="flex items-end justify-between gap-2">
                <span class="text-xs font-medium text-stone-700">引き継ぐ品番</span>
                <span class="text-xs text-stone-500 tabular-nums">{{ selected.size }} / {{ candidates.length }}品番を選択</span>
            </div>
            <div class="max-h-80 overflow-auto rounded-lg border border-stone-200">
                <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:sticky [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="w-10 px-3 py-2">
                                <input type="checkbox" class="size-4 accent-stone-900" :checked="allSelected" aria-label="すべて選ぶ" @change="toggleAll" />
                            </th>
                            <th scope="col" class="px-3 py-2">品番</th>
                            <th scope="col" class="px-3 py-2 text-right whitespace-nowrap">{{ fiscalYear - 1 }}年度の年間</th>
                            <th scope="col" class="px-3 py-2 whitespace-nowrap">手で直したところ</th>
                            <th scope="col" class="px-3 py-2 whitespace-nowrap">{{ fiscalYear }}年度の今の目標</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(candidate, index) in candidates"
                            :key="candidate.item_no"
                            class="cursor-pointer hover:bg-stone-50"
                            :class="index > 0 ? '[&>*]:border-t [&>*]:border-stone-100' : ''"
                            data-testid="carry-over-row"
                            @click="toggle(candidate.item_no)"
                        >
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-stone-900"
                                    :checked="selected.has(candidate.item_no)"
                                    :aria-label="`${candidate.item_no} を引き継ぐ`"
                                    @click.stop
                                    @change="toggle(candidate.item_no)"
                                />
                            </td>
                            <td class="px-3 py-2 font-mono text-[12.5px] font-medium text-stone-900">{{ candidate.item_no }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-stone-900 tabular-nums">{{ formatQuantity(candidate.annual_quantity) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-stone-600 tabular-nums">
                                <template v-if="candidate.monthly_override_count === 0 && candidate.sku_override_count === 0">なし</template>
                                <template v-else>月 {{ candidate.monthly_override_count }}か月・SKU {{ candidate.sku_override_count }}件</template>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap tabular-nums">
                                <span v-if="candidate.current_annual_quantity === null" class="text-stone-400">なし</span>
                                <span v-else :class="selected.has(candidate.item_no) ? 'font-semibold text-amber-700' : 'text-stone-700'"
                                    >{{ formatQuantity(candidate.current_annual_quantity) }}<template v-if="selected.has(candidate.item_no)">（置き換わります）</template></span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="overwriteCount > 0" class="text-xs text-amber-700">{{ overwriteCount }}品番は、{{ fiscalYear }}年度の今の目標が置き換わります。今の目標は履歴に残ります。</p>
            <p v-if="error" class="text-xs text-red-700">{{ error }}</p>
        </div>

        <div class="flex justify-end gap-2 border-t border-stone-200 pt-4">
            <button type="button" class="h-10 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">キャンセル</button>
            <button
                type="submit"
                class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving || selected.size === 0"
                data-testid="carry-over-submit"
            >
                {{ isSaving ? "引き継ぎ中…" : `${selected.size}品番を引き継ぐ` }}
            </button>
        </div>
    </form>
</template>
