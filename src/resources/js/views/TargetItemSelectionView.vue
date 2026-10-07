<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from "vue";
import { confirmItemSelection, fetchCurrentItemSelection, fetchItemRanking } from "@/api/itemSelections";
import { ApiError } from "@/api/client";
import ItemSelectionHistory from "@/components/ItemSelectionHistory.vue";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import type { CurrentItemSelection, ItemRanking } from "@/types/api";

/**
 * 対象品番（バックログ B-005）。直近12か月の売上順位（K-032）を見て、在庫推移の対象にする品番を確定する。
 * 毎年4月の見直しが基本で、いつでも変更できる（K-030）。確定できるのは最大100品番（K-031）。確定の履歴はモーダルで確認する。
 */

const isHistoryOpen = ref(false);
const historyButton = useTemplateRef<HTMLButtonElement>("historyButton");
const historyCloseButton = useTemplateRef<HTMLButtonElement>("historyCloseButton");

function onHistoryKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeHistory();
    }
}

async function openHistory(): Promise<void> {
    isHistoryOpen.value = true;
    document.addEventListener("keydown", onHistoryKeydown);
    await nextTick();
    historyCloseButton.value?.focus();
}

function closeHistory(): void {
    isHistoryOpen.value = false;
    document.removeEventListener("keydown", onHistoryKeydown);
    historyButton.value?.focus();
}

onBeforeUnmount(() => document.removeEventListener("keydown", onHistoryKeydown));

const ranking = ref<ItemRanking | null>(null);
const current = ref<CurrentItemSelection | null>(null);
const selected = ref(new Set<string>());
const note = ref("");
const isLoading = ref(false);
const hasLoadError = ref(false);
const isConfirming = ref(false);
const confirmError = ref<string | null>(null);
const confirmedMessage = ref<string | null>(null);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const formatDate = (value: string): string => value.replaceAll("-", "/");
const formatYen = (value: number): string => `¥${value.toLocaleString()}`;

async function load(): Promise<void> {
    isLoading.value = true;
    hasLoadError.value = false;
    try {
        const [rankingResponse, currentResponse] = await Promise.all([fetchItemRanking(), fetchCurrentItemSelection()]);
        ranking.value = rankingResponse;
        current.value = currentResponse;
        selected.value = currentResponse.data ? currentItemNos.value : candidateItemNos.value;
    } catch {
        hasLoadError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);

const maxItems = computed(() => ranking.value?.meta.max_items ?? 100);
const candidateRank = computed(() => ranking.value?.meta.candidate_rank ?? 20);
const fiscalYear = computed(() => (current.value ? Number(current.value.meta.fiscal_year_start.slice(0, 4)) : null));

const candidateItemNos = computed(() => new Set((ranking.value?.data ?? []).filter((rank) => rank.is_candidate).map((rank) => rank.item_no)));

/** 今の対象品番のうち、CrossWalker に残っている品番（消えた品番は確定し直せない）。 */
const currentItemNos = computed(() => new Set((current.value?.data?.items ?? []).filter((item) => item.exists_in_crosswalker).map((item) => item.item_no)));
const vanishedItemNos = computed(() => (current.value?.data?.items ?? []).filter((item) => !item.exists_in_crosswalker).map((item) => item.item_no));

/** 今の対象品番が今年度（4月1日以降）に確定されたものか。 */
const isConfirmedThisFiscalYear = computed(() => {
    const selection = current.value?.data;
    if (!selection || !current.value) {
        return false;
    }
    return formatDateInTokyo(selection.confirmed_at) >= current.value.meta.fiscal_year_start;
});

function formatDateInTokyo(value: string): string {
    return new Intl.DateTimeFormat("sv-SE", { timeZone: "Asia/Tokyo" }).format(new Date(value));
}

const addedCount = computed(() => [...selected.value].filter((itemNo) => !currentItemNos.value.has(itemNo)).length);
const removedCount = computed(() => [...currentItemNos.value].filter((itemNo) => !selected.value.has(itemNo)).length);
const isOverLimit = computed(() => selected.value.size > maxItems.value);
const canConfirm = computed(() => selected.value.size > 0 && !isOverLimit.value && !isConfirming.value);

function toggle(itemNo: string): void {
    const next = new Set(selected.value);
    if (next.has(itemNo)) {
        next.delete(itemNo);
    } else {
        next.add(itemNo);
    }
    selected.value = next;
    confirmedMessage.value = null;
}

function selectCandidates(): void {
    selected.value = new Set(candidateItemNos.value);
    confirmedMessage.value = null;
}

function restoreCurrent(): void {
    selected.value = new Set(currentItemNos.value);
    confirmedMessage.value = null;
}

async function confirm(): Promise<void> {
    if (!canConfirm.value || !ranking.value) {
        return;
    }
    isConfirming.value = true;
    confirmError.value = null;
    try {
        const order = ranking.value.data.map((rank) => rank.item_no);
        const itemNos = [...selected.value].sort((a, b) => order.indexOf(a) - order.indexOf(b));
        const selection = await confirmItemSelection(itemNos, note.value.trim() || null);
        current.value = { data: selection, meta: current.value?.meta ?? { fiscal_year_start: "" } };
        note.value = "";
        confirmedMessage.value = `対象品番を確定しました（${selection.items.length}品番）。`;
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            confirmError.value = Object.values(error.errors).flat()[0] ?? error.message;
        }
    } finally {
        isConfirming.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-bold tracking-tight text-stone-900">対象品番</h1>
            <p class="text-sm text-stone-500">需要予測と在庫推移の対象にする品番を、直近12か月の売上順位を見て確定します。毎年4月に見直します。</p>
        </header>

        <div v-if="hasLoadError" class="flex flex-col items-center gap-3 rounded-lg border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">対象品番を表示できませんでした。</p>
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!ranking || !current" class="rounded-lg border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <template v-else>
            <section
                class="flex flex-col gap-3 rounded-lg border px-4 py-3 text-sm sm:flex-row sm:items-start sm:justify-between"
                :class="isConfirmedThisFiscalYear ? 'border-stone-200 bg-white' : 'border-amber-300 bg-amber-50'"
                data-testid="current-selection"
            >
                <div class="flex flex-col gap-1">
                    <template v-if="!current.data">
                        <p class="font-semibold text-amber-900">対象品番がまだ確定されていません。</p>
                        <p class="text-amber-800">売上順位{{ candidateRank }}位以内の品番が選ばれた状態になっています。内容を確認して確定してください。</p>
                    </template>
                    <template v-else>
                        <p v-if="!isConfirmedThisFiscalYear" class="font-semibold text-amber-900">{{ fiscalYear }}年度の対象品番がまだ確定されていません。見直して確定してください。</p>
                        <p :class="isConfirmedThisFiscalYear ? 'text-stone-700' : 'text-amber-800'">
                            今の対象品番：<span class="font-semibold tabular-nums">{{ current.data.items.length }}品番</span>（{{ formatDateTime(current.data.confirmed_at) }}
                            {{ current.data.confirmed_by ?? "確定した人の記録なし" }} 確定）
                        </p>
                        <p v-if="current.data.note" class="text-stone-500">メモ：{{ current.data.note }}</p>
                    </template>
                    <p v-if="vanishedItemNos.length > 0" class="text-amber-800">
                        CrossWalker から消えた品番が含まれています（<span class="font-mono">{{ vanishedItemNos.join("、") }}</span
                        >）。次に確定すると対象から外れます。
                    </p>
                </div>
                <button
                    v-if="current.data"
                    ref="historyButton"
                    type="button"
                    class="shrink-0 self-start rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
                    aria-haspopup="dialog"
                    @click="openHistory"
                >
                    確定履歴
                </button>
            </section>

            <div class="flex flex-col gap-4">
                <section class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
                    <div class="flex flex-col gap-2 border-b border-stone-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-[13px] text-stone-500 tabular-nums">
                            売上の集計期間：{{ formatDate(ranking.meta.ranking_from) }} 〜 {{ formatDate(ranking.meta.ranking_to) }}（直近12か月、税込）
                        </p>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100" @click="selectCandidates">
                                上位{{ candidateRank }}品番を選ぶ
                            </button>
                            <button
                                v-if="current.data"
                                type="button"
                                class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100"
                                @click="restoreCurrent"
                            >
                                今の対象品番に戻す
                            </button>
                        </div>
                    </div>

                    <div v-if="ranking.data.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                        品番がまだ登録されていません。「品番・SKU」の画面で CrossWalker から取得してください。
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                                <tr>
                                    <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">対象にする</span></th>
                                    <th scope="col" class="px-3 py-2.5 text-right">順位</th>
                                    <th scope="col" class="px-3 py-2.5">品番</th>
                                    <th scope="col" class="px-3 py-2.5">ブランド</th>
                                    <th scope="col" class="px-3 py-2.5">カテゴリー</th>
                                    <th scope="col" class="px-3 py-2.5 text-right">SKU数</th>
                                    <th scope="col" class="px-3 py-2.5 text-right">売上金額</th>
                                    <th scope="col" class="px-3 py-2.5 text-right">販売数</th>
                                    <th scope="col" class="px-3 py-2.5">状態</th>
                                    <th scope="col" class="px-3 py-2.5 whitespace-nowrap">今の対象</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                <tr
                                    v-for="rank in ranking.data"
                                    :key="rank.item_id"
                                    class="cursor-pointer hover:bg-stone-50"
                                    :class="selected.has(rank.item_no) ? 'bg-stone-50' : ''"
                                    @click="toggle(rank.item_no)"
                                >
                                    <td class="px-3 py-2.5">
                                        <input
                                            type="checkbox"
                                            class="size-4 rounded border-stone-300 text-stone-900 focus:ring-stone-900"
                                            :checked="selected.has(rank.item_no)"
                                            :aria-label="`${rank.item_no} を対象にする`"
                                            @click.stop
                                            @change="toggle(rank.item_no)"
                                        />
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-stone-700 tabular-nums">{{ rank.sales_rank ?? "—" }}</td>
                                    <td class="px-3 py-2.5 font-mono font-semibold whitespace-nowrap text-stone-900">{{ rank.item_no }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-stone-700">{{ rank.brand }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-stone-700">{{ rank.category }}</td>
                                    <td class="px-3 py-2.5 text-right text-stone-700 tabular-nums">{{ rank.sku_count }}</td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums">
                                        <span v-if="rank.sales_amount !== null" class="text-stone-900">{{ formatYen(rank.sales_amount) }}</span>
                                        <span v-else class="text-stone-400">実績なし</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-stone-700 tabular-nums">{{ rank.sales_quantity?.toLocaleString() ?? "—" }}</td>
                                    <td class="px-3 py-2.5"><StatusBadge :tone="rank.status === 'active' ? 'positive' : 'neutral'" :label="rank.status_label" /></td>
                                    <td class="px-3 py-2.5 text-xs text-stone-500">{{ currentItemNos.has(rank.item_no) ? "対象" : "" }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <form class="sticky bottom-0 flex flex-col gap-2 rounded-lg border border-stone-300 bg-white px-4 py-3 shadow-sm" @submit.prevent="confirm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="flex flex-col text-sm sm:w-64" data-testid="selection-summary">
                            <p class="text-stone-900">
                                選択中 <span class="font-semibold tabular-nums">{{ selected.size }}</span> 品番<span class="text-stone-500"> ／ 最大{{ maxItems }}品番</span>
                            </p>
                            <p v-if="current.data && (addedCount || removedCount)" class="text-xs text-stone-500 tabular-nums">今の対象から 追加 {{ addedCount }}・外す {{ removedCount }}</p>
                            <p v-if="isOverLimit" class="text-xs font-semibold text-red-700">{{ maxItems }}品番以内にしてください。</p>
                        </div>
                        <label for="selection-note" class="sr-only">メモ</label>
                        <input
                            id="selection-note"
                            v-model="note"
                            type="text"
                            maxlength="500"
                            placeholder="メモ（見直しの理由など、任意）"
                            class="min-w-0 flex-1 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm placeholder:text-stone-400 focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none"
                        />
                        <button
                            type="submit"
                            class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold whitespace-nowrap text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!canConfirm"
                        >
                            {{ isConfirming ? "確定中…" : "この内容で確定" }}
                        </button>
                    </div>
                    <p v-if="confirmError" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ confirmError }}</p>
                    <p v-if="confirmedMessage" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ confirmedMessage }}</p>
                </form>
            </div>

            <div v-if="isHistoryOpen" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" data-testid="history-backdrop" @click.self="closeHistory">
                <div role="dialog" aria-modal="true" aria-labelledby="history-title" class="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-stone-50 shadow-xl">
                    <header class="flex items-center justify-between border-b border-stone-200 bg-white px-4 py-3">
                        <h2 id="history-title" class="text-base font-bold text-stone-900">確定履歴</h2>
                        <button
                            ref="historyCloseButton"
                            type="button"
                            class="flex size-8 items-center justify-center rounded text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                            aria-label="閉じる"
                            @click="closeHistory"
                        >
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <path d="m5 5 10 10M15 5 5 15" />
                            </svg>
                        </button>
                    </header>
                    <div class="overflow-y-auto p-4">
                        <ItemSelectionHistory />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
