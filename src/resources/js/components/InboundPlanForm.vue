<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { fetchItemForecast } from "@/api/demandForecasts";
import { createInboundPlan, updateInboundPlan } from "@/api/inboundPlans";
import { allocateByWeights } from "@/components/inboundPlan/autoAllocate";
import type { InboundPlan, Item } from "@/types/api";

/**
 * 入荷予定の登録・変更（バックログ B-007、決定記録 K-043）。品番・入荷予定月・数量を入れ、SKUへの割り振りは分かっている分だけ入れる。
 * 割り振っていない残りは、在庫推移で需要予測の比率により仮に割り振られる。
 * 「自動で割り振る」を押すと、まだ割り振っていない残りを、在庫推移の仮の割り振りと同じ比率（SKUの基準平均日販）で入力欄に入れる（K-054）。
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
        autoAllocateNote.value = null;
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

const isAutoAllocating = ref(false);
/** 自動で割り振った結果のお知らせ（割り振れなかったときは理由） */
const autoAllocateNote = ref<{ text: string; isError: boolean } | null>(null);
const remaining = computed(() => quantity.value - allocatedTotal.value);

/** まだ割り振っていない残りを、SKUの需要予測の比率で割り振る。入力済みの数はそのまま残す。 */
async function autoAllocate(): Promise<void> {
    const item = selectedItem.value;
    if (!item || remaining.value <= 0) {
        return;
    }
    isAutoAllocating.value = true;
    autoAllocateNote.value = null;
    try {
        const forecasts = await fetchItemForecast(item.item_no);
        const weights = new Map(forecasts.filter((sku) => sku.base_average !== null && sku.base_average > 0).map((sku) => [sku.sku_id, sku.base_average!]));
        const added = allocateByWeights(remaining.value, weights);
        if (added.size === 0) {
            autoAllocateNote.value = { text: "需要予測の出せるSKUが無いため、自動で割り振れません。", isError: true };
            return;
        }
        const total = [...added.values()].reduce((sum, value) => sum + value, 0);
        allocations.value = { ...allocations.value, ...Object.fromEntries([...added].map(([skuId, value]) => [skuId, toNumber(allocations.value[skuId]) + value])) };
        const skipped = item.skus.length - weights.size;
        autoAllocateNote.value = {
            text: `残り ${total.toLocaleString()} を需要予測の比率で割り振りました。${skipped > 0 ? `需要予測の出せないSKU（${skipped}件）には割り振っていません。` : ""}`,
            isError: false,
        };
    } catch {
        // 通信エラーは全体通知に表示される
    } finally {
        isAutoAllocating.value = false;
    }
}

function clearAllocations(): void {
    allocations.value = {};
    autoAllocateNote.value = null;
}

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

const inputClass = "h-10 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
const labelClass = "text-xs font-medium text-stone-700";

/** 割り振り済みの割合（細い帯の長さ）。入荷予定数を超えたら100%にする。 */
const allocatedPercent = computed(() => `${quantity.value > 0 ? Math.min(100, (allocatedTotal.value / quantity.value) * 100) : 0}%`);
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="flex flex-col gap-1.5 sm:col-span-1">
                <span :class="labelClass">品番</span>
                <select v-model="form.itemNo" :class="inputClass" data-testid="item-select">
                    <option value="" disabled>選んでください</option>
                    <option v-for="item in items" :key="item.id" :value="item.item_no">{{ item.item_no }}（{{ item.brand }}・{{ item.category }}）</option>
                </select>
                <span v-if="errorOf('item_no')" class="text-xs text-red-700">{{ errorOf("item_no") }}</span>
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="labelClass">入荷予定月</span>
                <input v-model="form.arrivalMonth" type="month" :class="inputClass" data-testid="arrival-month" />
                <span v-if="errorOf('arrival_month')" class="text-xs text-red-700">{{ errorOf("arrival_month") }}</span>
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="labelClass">入荷予定数<span class="ml-1 font-normal text-stone-500">（品番全体）</span></span>
                <input v-model="form.quantity" type="number" min="1" inputmode="numeric" :class="inputClass" class="text-right tabular-nums" data-testid="quantity" />
                <span v-if="errorOf('quantity')" class="text-xs text-red-700">{{ errorOf("quantity") }}</span>
            </label>
        </div>
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">メモ<span class="ml-1 font-normal text-stone-500">（発注先・発注番号など、任意）</span></span>
            <input v-model="form.note" type="text" maxlength="500" placeholder="例：PO-001" :class="inputClass" />
            <span v-if="errorOf('note')" class="text-xs text-red-700">{{ errorOf("note") }}</span>
        </label>

        <section class="flex flex-col gap-2.5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h3 :class="labelClass">SKUへの割り振り<span class="ml-1 font-normal text-stone-500">（分かっている分だけ。後から入れられます）</span></h3>
                <div class="flex items-center gap-2.5">
                    <span class="h-1.5 w-28 overflow-hidden rounded-full bg-stone-200" aria-hidden="true">
                        <span class="block h-full rounded-full" :class="isOverAllocated ? 'bg-red-600' : 'bg-stone-700'" :style="{ width: allocatedPercent }" />
                    </span>
                    <p class="text-xs tabular-nums" :class="isOverAllocated ? 'font-semibold text-red-700' : 'text-stone-600'" data-testid="allocation-summary">
                        割り振り {{ allocatedTotal.toLocaleString() }} ／ {{ quantity.toLocaleString() }}
                        <template v-if="!isOverAllocated">（未割り振り {{ (quantity - allocatedTotal).toLocaleString() }}）</template>
                        <template v-else>（入荷予定数を超えています）</template>
                    </p>
                </div>
            </div>
            <div v-if="selectedItem" class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 text-xs font-medium text-stone-900 hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="isAutoAllocating || remaining <= 0"
                    :title="remaining <= 0 ? '割り振っていない残りがありません' : '割り振っていない残りを、需要予測の比率でSKUに割り振ります'"
                    data-testid="auto-allocate"
                    @click="autoAllocate"
                >
                    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6.5 2.5 7.6 5.4l2.9 1.1-2.9 1.1-1.1 2.9-1.1-2.9-2.9-1.1 2.9-1.1Z" />
                        <path d="m12 9.5.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6Z" />
                        <path d="M12.5 1.5v2.5M11.25 2.75h2.5" />
                    </svg>
                    {{ isAutoAllocating ? "割り振り中…" : "残りを自動で割り振る" }}
                </button>
                <button
                    type="button"
                    class="h-8 rounded-lg px-2.5 text-xs text-stone-600 hover:bg-stone-100 hover:text-stone-900 disabled:opacity-40"
                    :disabled="allocatedTotal === 0"
                    data-testid="clear-allocations"
                    @click="clearAllocations"
                >
                    割り振りを消す
                </button>
                <span v-if="autoAllocateNote" class="text-xs" :class="autoAllocateNote.isError ? 'text-amber-700' : 'text-stone-500'" data-testid="auto-allocate-note">{{
                    autoAllocateNote.text
                }}</span>
            </div>
            <p v-if="!selectedItem" class="rounded-lg border border-dashed border-stone-300 px-3 py-6 text-center text-[13px] text-stone-500">品番を選ぶと、SKUが表示されます。</p>
            <div v-else class="max-h-72 overflow-y-auto rounded-lg border border-stone-200">
                <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:sticky [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-3 py-2">SKUコード</th>
                            <th scope="col" class="w-36 px-3 py-2 text-right">割り振る数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(sku, index) in selectedItem.skus" :key="sku.id" class="hover:bg-stone-50" :class="index > 0 ? '[&>*]:border-t [&>*]:border-stone-100' : ''">
                            <td class="px-3 py-1.5 font-mono text-[12.5px] text-stone-800">{{ sku.sku_code }}</td>
                            <td class="px-3 py-1.5">
                                <input
                                    v-model="allocations[sku.id]"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    placeholder="0"
                                    class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-right text-sm tabular-nums placeholder:text-stone-300 focus:border-stone-900 focus:outline-none"
                                    :aria-label="`${sku.sku_code} に割り振る数`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <span v-if="allocationError" class="text-xs text-red-700">{{ allocationError }}</span>
        </section>

        <div class="flex justify-end gap-2 border-t border-stone-200 pt-4">
            <button type="button" class="h-10 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">キャンセル</button>
            <button
                type="submit"
                class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving || isOverAllocated"
            >
                {{ isSaving ? "保存中…" : "保存" }}
            </button>
        </div>
    </form>
</template>
