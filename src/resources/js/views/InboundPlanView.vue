<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef } from "vue";
import { fetchAllItems } from "@/api/items";
import { deleteInboundPlan, fetchInboundPlans, setInboundPlanReceived } from "@/api/inboundPlans";
import InboundPlanForm from "@/components/InboundPlanForm.vue";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import type { InboundPlan, Item, Paginated } from "@/types/api";

/**
 * 入荷予定（バックログ B-007、決定記録 K-043）。品番・月単位で入荷予定を登録し、SKUへの割り振りは分かったときに入れる。
 * 割り振っていない残りは、在庫推移（B-008）で需要予測の比率により仮に割り振る。入荷したら「入荷済み」にし、在庫推移から外す。
 */

const result = ref<Paginated<InboundPlan> | null>(null);
const items = ref<Item[]>([]);
const isLoading = ref(false);
const hasError = ref(false);
const filters = reactive({ keyword: "", includeReceived: false, page: 1 });
const editing = ref<{ plan: InboundPlan | null } | null>(null);
const message = ref<string | null>(null);

const dateFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit" });
const formatMonth = (value: string): string => `${value.slice(0, 4)}年${Number(value.slice(5, 7))}月`;
const formatNumber = (value: number): string => value.toLocaleString();

let latestRequest = 0;

async function load(): Promise<void> {
    const requestId = ++latestRequest;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchInboundPlans({ keyword: filters.keyword.trim() || undefined, include_received: filters.includeReceived, page: filters.page });
        if (requestId === latestRequest) {
            result.value = response;
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

onMounted(async () => {
    void load();
    try {
        items.value = await fetchAllItems();
    } catch {
        // 通信エラーは全体通知に表示される
    }
});

function search(): void {
    filters.page = 1;
    void load();
}

function goToPage(page: number): void {
    filters.page = page;
    void load();
}

const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeForm();
    }
}

async function openForm(plan: InboundPlan | null): Promise<void> {
    editing.value = { plan };
    message.value = null;
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    closeButton.value?.focus();
}

function closeForm(): void {
    editing.value = null;
    document.removeEventListener("keydown", onKeydown);
}

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

function onSaved(plan: InboundPlan): void {
    message.value = `${plan.item_no} の ${formatMonth(plan.arrival_month)} の入荷予定を保存しました。`;
    closeForm();
    void load();
}

async function toggleReceived(plan: InboundPlan): Promise<void> {
    try {
        const updated = await setInboundPlanReceived(plan.id, plan.received_at === null);
        message.value = updated.received_at
            ? `${plan.item_no} の ${formatMonth(plan.arrival_month)} の入荷予定を入荷済みにしました。在庫推移には入りません。`
            : `${plan.item_no} の入荷済みを取り消しました。`;
        void load();
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

async function remove(plan: InboundPlan): Promise<void> {
    if (!window.confirm(`${plan.item_no} の ${formatMonth(plan.arrival_month)} の入荷予定（${formatNumber(plan.quantity)}）を削除しますか？`)) {
        return;
    }
    try {
        await deleteInboundPlan(plan.id);
        message.value = `${plan.item_no} の ${formatMonth(plan.arrival_month)} の入荷予定を削除しました。`;
        void load();
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

function allocationSummary(plan: InboundPlan): string {
    return plan.allocations.map((allocation) => `${allocation.sku_code} ${formatNumber(allocation.quantity)}`).join("、");
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-bold tracking-tight text-stone-900">入荷予定</h1>
                <p class="text-sm text-stone-500">
                    品番ごとに、入荷予定月と数量を登録します。SKUへの割り振りは分かったときに入れてください。割り振っていない分は、在庫推移で需要予測の比率により仮に割り振ります。
                </p>
            </div>
            <button type="button" class="shrink-0 self-start rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700 sm:self-auto" @click="openForm(null)">
                入荷予定を追加
            </button>
        </header>

        <p v-if="message" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ message }}</p>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form class="flex w-full gap-2 sm:max-w-md" role="search" @submit.prevent="search">
                <label for="inbound-keyword" class="sr-only">品番で検索</label>
                <input
                    id="inbound-keyword"
                    v-model="filters.keyword"
                    type="search"
                    placeholder="品番で検索"
                    class="min-w-0 flex-1 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm placeholder:text-stone-400 focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none"
                />
                <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">検索</button>
            </form>
            <label class="inline-flex items-center gap-2 text-sm text-stone-700">
                <input v-model="filters.includeReceived" type="checkbox" class="size-4 rounded border-stone-300 text-stone-900 focus:ring-stone-900" @change="search" />
                入荷済みも表示
            </label>
        </div>

        <section class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">入荷予定を表示できませんでした。</p>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="result.data.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ filters.keyword ? "条件に一致する入荷予定はありません。" : "入荷予定はまだありません。「入荷予定を追加」から登録してください。" }}
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                        <tr>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">入荷予定月</th>
                            <th scope="col" class="px-3 py-2.5">品番</th>
                            <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">入荷予定数</th>
                            <th scope="col" class="px-3 py-2.5">SKUへの割り振り</th>
                            <th scope="col" class="px-3 py-2.5">メモ</th>
                            <th scope="col" class="px-3 py-2.5">状態</th>
                            <th scope="col" class="px-3 py-2.5"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="plan in result.data" :key="plan.id" :class="plan.received_at ? 'text-stone-400' : ''" data-testid="plan-row">
                            <td class="px-3 py-2.5 whitespace-nowrap tabular-nums" :class="plan.received_at ? '' : 'text-stone-900'">{{ formatMonth(plan.arrival_month) }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span class="font-mono font-semibold" :class="plan.received_at ? '' : 'text-stone-900'">{{ plan.item_no }}</span>
                                <span v-if="plan.exists_in_crosswalker" class="ml-2 text-xs text-stone-500">{{ plan.brand }}・{{ plan.category }}</span>
                                <span v-else class="ml-2 text-xs text-amber-700">CrossWalkerから消えた品番</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="plan.received_at ? '' : 'text-stone-900'">{{ formatNumber(plan.quantity) }}</td>
                            <td class="px-3 py-2.5 text-[13px]">
                                <p class="tabular-nums">
                                    割り振り済み {{ formatNumber(plan.allocated_quantity) }}
                                    <span v-if="plan.unallocated_quantity > 0" class="ml-1" :class="plan.received_at ? '' : 'font-semibold text-amber-700'" data-testid="unallocated">
                                        未割り振り {{ formatNumber(plan.unallocated_quantity) }}（仮に割り振ります）
                                    </span>
                                </p>
                                <p v-if="plan.allocations.length > 0" class="mt-0.5 max-w-md truncate font-mono text-xs text-stone-500" :title="allocationSummary(plan)">
                                    {{ allocationSummary(plan) }}
                                </p>
                            </td>
                            <td class="max-w-56 truncate px-3 py-2.5 text-stone-600" :title="plan.note ?? undefined">{{ plan.note ?? "—" }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span v-if="plan.received_at" class="text-xs">入荷済み（{{ dateFormat.format(new Date(plan.received_at)) }}）</span>
                                <StatusBadge v-else-if="plan.is_overdue" tone="warning" label="予定月を過ぎています" />
                                <StatusBadge v-else tone="neutral" label="入荷予定" />
                            </td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                <button v-if="!plan.received_at" type="button" class="rounded px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-100" @click="openForm(plan)">編集</button>
                                <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-100" @click="toggleReceived(plan)">
                                    {{ plan.received_at ? "入荷済みを取り消す" : "入荷済みにする" }}
                                </button>
                                <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="remove(plan)">削除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="result && result.meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1"
                        @click="goToPage(result.meta.current_page - 1)"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:opacity-40"
                        :disabled="result.meta.current_page >= result.meta.last_page"
                        @click="goToPage(result.meta.current_page + 1)"
                    >
                        次へ
                    </button>
                </div>
            </footer>
        </section>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeForm">
            <div role="dialog" aria-modal="true" aria-labelledby="inbound-form-title" class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                <header class="flex items-center justify-between border-b border-stone-200 px-4 py-3">
                    <h2 id="inbound-form-title" class="text-base font-bold text-stone-900">{{ editing.plan ? "入荷予定を編集" : "入荷予定を追加" }}</h2>
                    <button
                        ref="closeButton"
                        type="button"
                        class="flex size-8 items-center justify-center rounded text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeForm"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto p-4">
                    <InboundPlanForm :plan="editing.plan" :items="items" @saved="onSaved" @cancel="closeForm" />
                </div>
            </div>
        </div>
    </div>
</template>
