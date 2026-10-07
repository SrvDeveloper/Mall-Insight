<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { createInboundPlan, updateInboundPlan } from "@/api/inboundPlans";
import type { InboundPlan, Item } from "@/types/api";

/**
 * 入荷予定の登録・変更（バックログ B-007、決定記録 K-043）。品番・入荷予定月・数量を入れ、SKUへの割り振りは分かっている分だけ入れる。
 * 割り振っていない残りは、在庫推移で需要予測の比率により仮に割り振られる。
 */

const props = defineProps<{ plan: InboundPlan | null; items: Item[] }>();
const emit = defineEmits<{ saved: [plan: InboundPlan]; cancel: [] }>();

const form = reactive({
    itemNo: props.plan?.item_no ?? "",
    arrivalMonth: props.plan?.arrival_month ?? "",
    // type="number" の入力欄の v-model は数値になる（空欄は ""）
    quantity: (props.plan?.quantity ?? "") as number | string,
    note: props.plan?.note ?? "",
});
/** SKU ID => 割り振る数（入力中の値。空欄は ""） */
const allocations = ref<Record<number, number | string>>(Object.fromEntries((props.plan?.allocations ?? []).map((allocation) => [allocation.sku_id, allocation.quantity])));
const isSaving = ref(false);
const errors = ref<Record<string, string[]>>({});

const selectedItem = computed(() => props.items.find((item) => item.item_no === form.itemNo) ?? null);

// 品番を変えたら、前の品番のSKUへの割り振りは消す
watch(
    () => form.itemNo,
    () => {
        const skuIds = new Set(selectedItem.value?.skus.map((sku) => sku.id) ?? []);
        allocations.value = Object.fromEntries(Object.entries(allocations.value).filter(([skuId]) => skuIds.has(Number(skuId))));
    },
);

/** 0以上の整数として読めない値（空欄・小数・負の数）は0とする。 */
function toNumber(value: number | string | undefined): number {
    const number = typeof value === "number" ? value : Number(String(value ?? "").trim() || "0");
    return Number.isInteger(number) && number > 0 ? number : 0;
}
const allocatedTotal = computed(() => Object.values(allocations.value).reduce<number>((total, value) => total + toNumber(value), 0));
const quantity = computed(() => toNumber(form.quantity));
const isOverAllocated = computed(() => allocatedTotal.value > quantity.value);

function errorOf(...keys: string[]): string | null {
    for (const key of keys) {
        const message = errors.value[key]?.[0];
        if (message) {
            return message;
        }
    }
    return null;
}

const allocationError = computed(() => errorOf("allocations", ...Object.keys(errors.value).filter((key) => key.startsWith("allocations."))));

async function save(): Promise<void> {
    if (isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = {};
    const payload = {
        item_no: form.itemNo,
        arrival_month: form.arrivalMonth,
        quantity: quantity.value,
        note: form.note.trim() || null,
        allocations: (selectedItem.value?.skus ?? []).map((sku) => ({ sku_id: sku.id, quantity: toNumber(allocations.value[sku.id]) })).filter((allocation) => allocation.quantity > 0),
    };
    try {
        const saved = props.plan ? await updateInboundPlan(props.plan.id, payload) : await createInboundPlan(payload);
        emit("saved", saved);
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = error.errors;
        }
    } finally {
        isSaving.value = false;
    }
}

const inputClass = "w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none";
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="save">
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="flex flex-col gap-1 text-sm sm:col-span-1">
                <span class="font-semibold text-stone-700">品番</span>
                <select v-model="form.itemNo" :class="inputClass" data-testid="item-select">
                    <option value="" disabled>選んでください</option>
                    <option v-for="item in items" :key="item.id" :value="item.item_no">{{ item.item_no }}（{{ item.brand }}・{{ item.category }}）</option>
                </select>
                <span v-if="errorOf('item_no')" class="text-xs text-red-700">{{ errorOf("item_no") }}</span>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-stone-700">入荷予定月</span>
                <input v-model="form.arrivalMonth" type="month" :class="inputClass" data-testid="arrival-month" />
                <span v-if="errorOf('arrival_month')" class="text-xs text-red-700">{{ errorOf("arrival_month") }}</span>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-stone-700">入荷予定数（品番全体）</span>
                <input v-model="form.quantity" type="number" min="1" inputmode="numeric" :class="inputClass" class="text-right tabular-nums" data-testid="quantity" />
                <span v-if="errorOf('quantity')" class="text-xs text-red-700">{{ errorOf("quantity") }}</span>
            </label>
        </div>
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-semibold text-stone-700">メモ<span class="ml-1 font-normal text-stone-500">（発注先・発注番号など、任意）</span></span>
            <input v-model="form.note" type="text" maxlength="500" :class="inputClass" />
            <span v-if="errorOf('note')" class="text-xs text-red-700">{{ errorOf("note") }}</span>
        </label>

        <section class="flex flex-col gap-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-stone-700">SKUへの割り振り<span class="ml-1 font-normal text-stone-500">（分かっている分だけ。後から入れられます）</span></h3>
                <p class="text-sm tabular-nums" :class="isOverAllocated ? 'font-semibold text-red-700' : 'text-stone-600'" data-testid="allocation-summary">
                    割り振り {{ allocatedTotal.toLocaleString() }} ／ {{ quantity.toLocaleString() }}
                    <template v-if="!isOverAllocated">（未割り振り {{ (quantity - allocatedTotal).toLocaleString() }}）</template>
                    <template v-else>（入荷予定数を超えています）</template>
                </p>
            </div>
            <p v-if="!selectedItem" class="rounded-md bg-stone-50 px-3 py-4 text-center text-sm text-stone-500">品番を選ぶと、SKUが表示されます。</p>
            <div v-else class="max-h-72 overflow-y-auto rounded-md border border-stone-200">
                <table class="w-full text-left text-[13px]">
                    <thead class="sticky top-0 border-b border-stone-200 bg-stone-50 text-xs text-stone-500">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">SKUコード</th>
                            <th scope="col" class="w-36 px-3 py-2 text-right font-medium">割り振る数</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="sku in selectedItem.skus" :key="sku.id">
                            <td class="px-3 py-1.5 font-mono text-stone-800">{{ sku.sku_code }}</td>
                            <td class="px-3 py-1.5">
                                <input
                                    v-model="allocations[sku.id]"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    placeholder="0"
                                    class="w-full rounded border border-stone-300 px-2 py-1 text-right text-sm tabular-nums focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none"
                                    :aria-label="`${sku.sku_code} に割り振る数`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <span v-if="allocationError" class="text-xs text-red-700">{{ allocationError }}</span>
        </section>

        <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">キャンセル</button>
            <button
                type="submit"
                class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving || isOverAllocated"
            >
                {{ isSaving ? "保存中…" : "保存" }}
            </button>
        </div>
    </form>
</template>
