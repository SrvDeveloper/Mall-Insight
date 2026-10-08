<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef, watch } from "vue";
import { fetchAllItems } from "@/api/items";
import { deleteInboundPlan, fetchInboundPlans, setInboundPlanReceived } from "@/api/inboundPlans";
import InboundPlanForm from "@/components/InboundPlanForm.vue";
import type { InboundPlan, InboundPlanPage, Item } from "@/types/api";

/**
 * 入荷予定（バックログ B-007、決定記録 K-043）。品番・月単位で入荷予定を登録し、SKUへの割り振りは分かったときに入れる。
 * 割り振っていない残りは、在庫推移（B-008）で需要予測の比率により仮に割り振る。入荷したら「入荷済み」にし、在庫推移から外す。
 *
 * 見た目は在庫推移の画面（K-047）とそろえる。上にまとめの帯を置き、一覧は入荷予定月ごとにまとめ、割り振りの進み具合を細い帯で示す（SKUごとの内訳は編集の画面で見る）。
 */

const result = ref<InboundPlanPage | null>(null);
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

function setIncludeReceived(value: boolean): void {
    if (filters.includeReceived !== value) {
        filters.includeReceived = value;
        search();
    }
}

// 品番の検索は、入力が止まってから一覧を読み込み直す
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => filters.keyword,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(search, 300);
    },
);
onBeforeUnmount(() => clearTimeout(searchTimer));

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

const summary = computed(() => result.value?.meta.summary ?? null);

/** まとめの帯（入荷前の件数・未割り振り・予定月を過ぎたもの・入荷済み）。在庫推移の画面の帯とそろえる。 */
const facts = computed(() => {
    const value = summary.value;
    if (!value) {
        return [];
    }
    return [
        { key: "pending", label: "入荷前の入荷予定", value: `${formatNumber(value.pending_count)}件`, note: `合計 ${formatNumber(value.pending_quantity)}個`, warn: false },
        {
            key: "unallocated",
            label: "SKUに未割り振り",
            value: `${formatNumber(value.unallocated_quantity)}個`,
            note: value.unallocated_quantity > 0 ? "在庫推移では需要予測の比率で仮に割り振ります" : "すべて割り振り済みです",
            warn: false,
        },
        {
            key: "overdue",
            label: "予定月を過ぎたもの",
            value: `${formatNumber(value.overdue_count)}件`,
            note: value.overdue_count > 0 ? "入荷済みにするか、入荷予定月を直してください" : "ありません",
            warn: value.overdue_count > 0,
        },
        { key: "received", label: "入荷済み", value: `${formatNumber(value.received_count)}件`, note: "在庫推移には入りません", warn: false },
    ];
});

interface MonthGroup {
    month: string;
    plans: InboundPlan[];
    quantity: number;
    isOverdue: boolean;
}

/** 表示中のページの入荷予定を、入荷予定月ごとにまとめる（一覧は入荷予定月の古い順）。 */
const monthGroups = computed<MonthGroup[]>(() => {
    const groups: MonthGroup[] = [];
    for (const plan of result.value?.data ?? []) {
        let group = groups.at(-1);
        if (!group || group.month !== plan.arrival_month) {
            group = { month: plan.arrival_month, plans: [], quantity: 0, isOverdue: false };
            groups.push(group);
        }
        group.plans.push(plan);
        group.quantity += plan.quantity;
        group.isOverdue ||= plan.is_overdue;
    }
    return groups;
});

/** 割り振り済みの割合（細い帯の長さ）。 */
function allocatedPercent(plan: InboundPlan): string {
    return `${plan.quantity > 0 ? Math.min(100, (plan.allocated_quantity / plan.quantity) * 100) : 0}%`;
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 入荷予定</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">入荷予定</h1>
                <p class="max-w-3xl text-[13px] text-stone-600">
                    品番ごとに、入荷予定月と数量を登録します。SKUへの割り振りは分かったときに入れてください。割り振っていない分は、在庫推移で需要予測の比率により仮に割り振ります。
                </p>
            </div>
            <button
                type="button"
                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700"
                data-testid="add-plan"
                @click="openForm(null)"
            >
                <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M8 3v10M3 8h10" />
                </svg>
                入荷予定を追加
            </button>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
            <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m3.5 8.5 3 3 6-7" />
            </svg>
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <section v-if="summary" class="grid grid-cols-2 rounded-xl border border-stone-200 bg-white lg:grid-cols-4" aria-label="入荷予定のまとめ" data-testid="inbound-summary">
            <div
                v-for="(fact, index) in facts"
                :key="fact.key"
                class="flex flex-col gap-1 px-5 py-4"
                :class="index > 0 ? 'border-stone-200 max-lg:odd:border-t max-lg:even:border-l lg:border-l' : ''"
                :data-testid="`summary-${fact.key}`"
            >
                <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                <span class="text-base font-semibold tabular-nums" :class="fact.warn ? 'text-amber-800' : 'text-stone-900'">{{ fact.value }}</span>
                <span class="text-xs" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'">{{ fact.note }}</span>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                    <button
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="!filters.includeReceived ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                        :aria-pressed="!filters.includeReceived"
                        data-testid="filter-pending"
                        @click="setIncludeReceived(false)"
                    >
                        入荷前 <span class="text-stone-500 tabular-nums">{{ summary?.pending_count ?? "" }}</span>
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="filters.includeReceived ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                        :aria-pressed="filters.includeReceived"
                        data-testid="filter-all"
                        @click="setIncludeReceived(true)"
                    >
                        入荷済みも含む <span class="text-stone-500 tabular-nums">{{ summary ? summary.pending_count + summary.received_count : "" }}</span>
                    </button>
                </div>
                <form class="w-full lg:w-60" role="search" @submit.prevent="search">
                    <label class="flex h-9 items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900">
                        <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="m10.5 10.5 3 3" />
                        </svg>
                        <span class="sr-only">品番で絞り込み</span>
                        <input v-model="filters.keyword" type="search" placeholder="品番で絞り込み" class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400" />
                    </label>
                </form>
            </div>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">入荷予定を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="result.data.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center text-sm text-stone-500">
                <template v-if="filters.keyword">条件に一致する入荷予定はありません。</template>
                <template v-else>
                    入荷予定はまだありません。
                    <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-semibold text-stone-700 hover:bg-stone-100" @click="openForm(null)">
                        入荷予定を追加
                    </button>
                </template>
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-[60rem] border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">品番</th>
                            <th scope="col" class="px-3 py-2.5 text-right whitespace-nowrap">入荷予定数</th>
                            <th scope="col" class="px-3 py-2.5">SKUへの割り振り</th>
                            <th scope="col" class="px-3 py-2.5">メモ</th>
                            <th scope="col" class="px-3 py-2.5">状態</th>
                            <th scope="col" class="px-3 py-2.5"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in monthGroups" :key="group.month">
                            <tr class="h-10 bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="month-row">
                                <th scope="rowgroup" colspan="6" class="px-4 text-left font-normal">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="text-sm font-semibold text-stone-900 tabular-nums">{{ formatMonth(group.month) }}</span>
                                        <span class="text-xs text-stone-500 tabular-nums">{{ group.plans.length }}件・{{ formatNumber(group.quantity) }}個</span>
                                        <span v-if="group.isOverdue" class="rounded-full bg-amber-100 px-2 py-px text-[11px] font-semibold text-amber-800">予定月を過ぎています</span>
                                    </span>
                                </th>
                            </tr>
                            <tr
                                v-for="plan in group.plans"
                                :key="plan.id"
                                class="group hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100"
                                :class="plan.received_at ? 'text-stone-400' : 'text-stone-700'"
                                data-testid="plan-row"
                            >
                                <td class="py-3 pr-3 pl-7 align-top whitespace-nowrap">
                                    <span class="font-mono text-sm font-medium" :class="plan.received_at ? '' : 'text-stone-900'">{{ plan.item_no }}</span>
                                    <span v-if="plan.exists_in_crosswalker" class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600"
                                        >{{ plan.brand }}・{{ plan.category }}</span
                                    >
                                    <span v-else class="ml-2 text-[11px] text-amber-700">CrossWalkerから消えた品番</span>
                                </td>
                                <td class="px-3 py-3 text-right align-top text-sm font-semibold tabular-nums" :class="plan.received_at ? '' : 'text-stone-900'">{{ formatNumber(plan.quantity) }}</td>
                                <td class="px-3 py-3 align-top">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-28 shrink-0 overflow-hidden rounded-full bg-stone-200" aria-hidden="true">
                                            <span class="block h-full rounded-full" :class="plan.received_at ? 'bg-stone-300' : 'bg-stone-700'" :style="{ width: allocatedPercent(plan) }" />
                                        </span>
                                        <span class="text-xs whitespace-nowrap tabular-nums">割り振り済み {{ formatNumber(plan.allocated_quantity) }}</span>
                                        <span
                                            v-if="plan.unallocated_quantity > 0"
                                            class="rounded-full px-2 py-px text-[11px] whitespace-nowrap tabular-nums"
                                            :class="plan.received_at ? 'bg-stone-100' : 'bg-amber-50 font-semibold text-amber-800'"
                                            :title="plan.received_at ? undefined : '在庫推移では需要予測の比率で仮に割り振ります'"
                                            data-testid="unallocated"
                                        >
                                            未割り振り {{ formatNumber(plan.unallocated_quantity) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-56 truncate px-3 py-3 align-top" :class="plan.note ? '' : 'text-stone-300'" :title="plan.note ?? undefined">{{ plan.note ?? "—" }}</td>
                                <td class="px-3 py-3 align-top whitespace-nowrap">
                                    <span v-if="plan.received_at" class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-500 tabular-nums"
                                        >入荷済み {{ dateFormat.format(new Date(plan.received_at)) }}</span
                                    >
                                    <span v-else-if="plan.is_overdue" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">予定月を過ぎています</span>
                                    <span v-else class="rounded-full border border-stone-200 px-2 py-0.5 text-[11px] text-stone-600">入荷前</span>
                                </td>
                                <td class="px-3 py-2 text-right align-top whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            v-if="!plan.received_at"
                                            type="button"
                                            class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                                            @click="openForm(plan)"
                                        >
                                            編集
                                        </button>
                                        <button
                                            type="button"
                                            class="h-8 rounded-lg border px-2.5 text-xs font-medium"
                                            :class="plan.received_at ? 'border-stone-200 text-stone-600 hover:bg-stone-100' : 'border-stone-300 bg-white text-stone-900 hover:bg-stone-100'"
                                            @click="toggleReceived(plan)"
                                        >
                                            {{ plan.received_at ? "入荷済みを取り消す" : "入荷済みにする" }}
                                        </button>
                                        <button type="button" class="h-8 rounded-lg px-2.5 text-xs font-medium text-red-700 hover:bg-red-50" @click="remove(plan)">削除</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <footer v-if="result && result.meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-[13px] text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1"
                        @click="goToPage(result.meta.current_page - 1)"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:opacity-40"
                        :disabled="result.meta.current_page >= result.meta.last_page"
                        @click="goToPage(result.meta.current_page + 1)"
                    >
                        次へ
                    </button>
                </div>
            </footer>
        </section>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeForm">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inbound-form-title"
                class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
            >
                <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 id="inbound-form-title" class="text-base font-bold text-stone-900">{{ editing.plan ? "入荷予定を編集" : "入荷予定を追加" }}</h2>
                        <p class="text-xs text-stone-500">割り振っていない分は、在庫推移で需要予測の比率により仮に割り振ります。</p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeForm"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto px-5 py-5">
                    <InboundPlanForm :plan="editing.plan" :items="items" @saved="onSaved" @cancel="closeForm" />
                </div>
            </div>
        </div>
    </div>
</template>
