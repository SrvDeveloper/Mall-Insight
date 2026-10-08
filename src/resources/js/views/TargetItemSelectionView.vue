<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from "vue";
import { confirmItemSelection, fetchCurrentItemSelection, fetchItemRanking } from "@/api/itemSelections";
import { ApiError } from "@/api/client";
import ItemSelectionHistory from "@/components/ItemSelectionHistory.vue";
import type { CurrentItemSelection, ItemRank, ItemRanking } from "@/types/api";

/**
 * 対象品番（バックログ B-005）。直近12か月の売上順位（K-032）を見て、在庫推移の対象にする品番を確定する。
 * 毎年4月の見直しが基本で、いつでも変更できる（K-030）。確定できるのは最大100品番（K-031）。確定の履歴はモーダルで確認する。
 *
 * 見た目は在庫推移の画面（K-047）とそろえる。上に今の対象品番の帯、一覧には売上金額の帯と、今の対象からの変更（追加・外す）の札を付ける。
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

type RankFilter = "all" | "selected" | "current";

const filter = ref<RankFilter>("all");
const keyword = ref("");

const filterOptions = computed(() => [
    { value: "all" as RankFilter, label: "すべて", count: ranking.value?.data.length ?? 0 },
    { value: "selected" as RankFilter, label: "選択中", count: selected.value.size },
    ...(current.value?.data ? [{ value: "current" as RankFilter, label: "今の対象", count: currentItemNos.value.size }] : []),
]);

const visibleRanks = computed<ItemRank[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    return (ranking.value?.data ?? []).filter((rank) => {
        if (filter.value === "selected" && !selected.value.has(rank.item_no)) {
            return false;
        }
        if (filter.value === "current" && !currentItemNos.value.has(rank.item_no)) {
            return false;
        }
        return word === "" || rank.item_no.toLowerCase().includes(word) || rank.brand.toLowerCase().includes(word) || rank.category.toLowerCase().includes(word);
    });
});

/** 上位の候補（売上順位 candidateRank 位以内）の区切り線を引く行。絞り込んでいるときは引かない。 */
const lastCandidateItemNo = computed(() => {
    if (filter.value !== "all" || keyword.value.trim() !== "") {
        return null;
    }
    const candidates = (ranking.value?.data ?? []).filter((rank) => rank.is_candidate);
    return candidates.at(-1)?.item_no ?? null;
});

/** 売上金額の帯の長さ（1位を100%とする）。 */
const maxSalesAmount = computed(() => Math.max(1, ...(ranking.value?.data ?? []).map((rank) => rank.sales_amount ?? 0)));
function salesPercent(rank: ItemRank): string {
    return `${((rank.sales_amount ?? 0) / maxSalesAmount.value) * 100}%`;
}

/** 今の対象品番からの変更。確定したことが無ければ、選んだ品番はすべて「対象にする」。 */
function changeOf(rank: ItemRank): { label: string; tone: "keep" | "add" | "remove" } | null {
    const isSelected = selected.value.has(rank.item_no);
    const isCurrent = currentItemNos.value.has(rank.item_no);
    if (isSelected && isCurrent) {
        return { label: "対象", tone: "keep" };
    }
    if (isSelected) {
        return { label: current.value?.data ? "追加" : "対象にする", tone: "add" };
    }
    return isCurrent ? { label: "外す", tone: "remove" } : null;
}

/** 今の対象品番の帯。 */
const facts = computed(() => {
    const selection = current.value?.data;
    const value = ranking.value;
    if (!value || !current.value) {
        return [];
    }
    return [
        {
            key: "current",
            label: "今の対象品番",
            value: selection ? `${selection.items.length}品番` : "未確定",
            note: selection ? `${formatDateTime(selection.confirmed_at)} ${selection.confirmed_by ?? "確定した人の記録なし"} が確定` : "まだ確定されていません",
            warn: !selection,
        },
        {
            key: "fiscal-year",
            label: `${fiscalYear.value}年度の見直し`,
            value: isConfirmedThisFiscalYear.value ? "確定済み" : "未確定",
            note: isConfirmedThisFiscalYear.value ? "4月1日以降に確定しています" : "毎年4月に見直して確定します",
            warn: !isConfirmedThisFiscalYear.value,
        },
        {
            key: "ranking",
            label: "売上の集計期間",
            value: `${formatDate(value.meta.ranking_from)} 〜 ${formatDate(value.meta.ranking_to)}`,
            note: "直近12か月・税込",
            warn: false,
        },
    ];
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 対象品番</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">対象品番</h1>
                <p class="text-[13px] text-stone-600">需要予測と在庫推移の対象にする品番を、直近12か月の売上順位を見て確定します。毎年4月に見直します。</p>
            </div>
            <button
                v-if="current?.data"
                ref="historyButton"
                type="button"
                class="inline-flex h-10 items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-900 hover:bg-stone-50"
                aria-haspopup="dialog"
                data-testid="open-history"
                @click="openHistory"
            >
                <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="8" cy="8" r="5.5" />
                    <path d="M8 5v3l2 1.5" />
                </svg>
                確定履歴
            </button>
        </header>

        <div v-if="hasLoadError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">対象品番を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!ranking || !current" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <template v-else>
            <div
                v-if="!isConfirmedThisFiscalYear || vanishedItemNos.length > 0"
                class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                role="status"
                data-testid="selection-warning"
            >
                <svg class="mt-0.5 size-4 shrink-0 text-amber-600" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path
                        d="M8 1.5a1 1 0 0 1 .87.5l6 10.5A1 1 0 0 1 14 14H2a1 1 0 0 1-.87-1.5l6-10.5A1 1 0 0 1 8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"
                    />
                </svg>
                <div class="flex flex-col gap-0.5">
                    <template v-if="!current.data">
                        <p class="font-semibold">対象品番がまだ確定されていません。</p>
                        <p class="text-amber-800">売上順位{{ candidateRank }}位以内の品番が選ばれた状態になっています。内容を確認して確定してください。</p>
                    </template>
                    <p v-else-if="!isConfirmedThisFiscalYear" class="font-semibold">{{ fiscalYear }}年度の対象品番がまだ確定されていません。見直して確定してください。</p>
                    <p v-if="vanishedItemNos.length > 0" class="text-amber-800">
                        CrossWalker から消えた品番が含まれています（<span class="font-mono">{{ vanishedItemNos.join("、") }}</span
                        >）。次に確定すると対象から外れます。
                    </p>
                </div>
            </div>

            <section class="grid grid-cols-1 rounded-xl border border-stone-200 bg-white sm:grid-cols-3" aria-label="今の対象品番" data-testid="current-selection">
                <div
                    v-for="(fact, index) in facts"
                    :key="fact.key"
                    class="flex flex-col gap-1 px-5 py-4"
                    :class="index > 0 ? 'border-stone-200 max-sm:border-t sm:border-l' : ''"
                    :data-testid="`fact-${fact.key}`"
                >
                    <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                    <span class="text-base font-semibold tabular-nums" :class="fact.warn ? 'text-amber-800' : 'text-stone-900'">{{ fact.value }}</span>
                    <span class="truncate text-xs tabular-nums" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'" :title="fact.note">{{ fact.note }}</span>
                </div>
                <p v-if="current.data?.note" class="border-t border-stone-200 px-5 py-2.5 text-xs text-stone-500 sm:col-span-3" data-testid="current-note">メモ：{{ current.data.note }}</p>
            </section>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                            <button
                                v-for="option in filterOptions"
                                :key="option.value"
                                type="button"
                                class="h-8 rounded-full border bg-white px-3 text-[13px]"
                                :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                                :aria-pressed="filter === option.value"
                                :data-testid="`filter-${option.value}`"
                                @click="filter = option.value"
                            >
                                {{ option.label }} <span class="text-stone-500 tabular-nums">{{ option.count }}</span>
                            </button>
                        </div>
                        <div class="flex gap-1.5">
                            <button type="button" class="h-8 rounded-lg border border-stone-300 bg-white px-3 text-xs font-medium text-stone-900 hover:bg-stone-100" @click="selectCandidates">
                                上位{{ candidateRank }}品番を選ぶ
                            </button>
                            <button v-if="current.data" type="button" class="h-8 rounded-lg px-3 text-xs font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900" @click="restoreCurrent">
                                今の対象品番に戻す
                            </button>
                        </div>
                    </div>
                    <label class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
                        <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="m10.5 10.5 3 3" />
                        </svg>
                        <span class="sr-only">品番・ブランド・カテゴリーで絞り込み</span>
                        <input
                            v-model="keyword"
                            type="search"
                            placeholder="品番・ブランドで絞り込み"
                            class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400"
                        />
                    </label>
                </div>

                <div v-if="ranking.data.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">品番がまだ登録されていません。「品番・SKU」の画面で CrossWalker から取得してください。</div>

                <div v-else-if="visibleRanks.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">条件に一致する品番はありません。</div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] border-separate border-spacing-0 text-left text-[13px]">
                        <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                            <tr>
                                <th scope="col" class="w-12 py-2.5 pl-4"><span class="sr-only">対象にする</span></th>
                                <th scope="col" class="w-14 px-3 py-2.5 text-right">順位</th>
                                <th scope="col" class="px-3 py-2.5">品番</th>
                                <th scope="col" class="px-3 py-2.5 text-right">SKU数</th>
                                <th scope="col" class="w-72 px-3 py-2.5">売上金額</th>
                                <th scope="col" class="px-3 py-2.5 text-right">販売数</th>
                                <th scope="col" class="px-4 py-2.5 whitespace-nowrap">今の対象から</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="rank in visibleRanks" :key="rank.item_id">
                                <tr
                                    class="group h-11 cursor-pointer [&>*]:border-t [&>*]:border-stone-100"
                                    :class="selected.has(rank.item_no) ? 'bg-stone-50 hover:bg-stone-100' : 'hover:bg-stone-50'"
                                    data-testid="rank-row"
                                    @click="toggle(rank.item_no)"
                                >
                                    <td class="py-0 pl-4">
                                        <input
                                            type="checkbox"
                                            class="size-4 cursor-pointer rounded border-stone-300 accent-stone-900"
                                            :checked="selected.has(rank.item_no)"
                                            :aria-label="`${rank.item_no} を対象にする`"
                                            @click.stop
                                            @change="toggle(rank.item_no)"
                                        />
                                    </td>
                                    <td class="px-3 text-right tabular-nums" :class="rank.sales_rank !== null && rank.sales_rank <= candidateRank ? 'font-semibold text-stone-900' : 'text-stone-500'">
                                        {{ rank.sales_rank ?? "—" }}
                                    </td>
                                    <td class="px-3 whitespace-nowrap">
                                        <span class="font-mono text-sm font-medium text-stone-900" data-testid="item-no">{{ rank.item_no }}</span>
                                        <span class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ rank.brand }}・{{ rank.category }}</span>
                                        <span v-if="rank.status !== 'active'" class="ml-1.5 rounded-full bg-stone-100 px-2 py-px text-[11px] text-stone-500">{{ rank.status_label }}</span>
                                    </td>
                                    <td class="px-3 text-right text-stone-700 tabular-nums">{{ rank.sku_count }}</td>
                                    <td class="px-3">
                                        <div v-if="rank.sales_amount !== null" class="flex items-center gap-2.5">
                                            <span class="h-1.5 w-32 shrink-0 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                                <span class="block h-full rounded-full bg-stone-700" :style="{ width: salesPercent(rank) }" />
                                            </span>
                                            <span class="text-stone-900 tabular-nums">{{ formatYen(rank.sales_amount) }}</span>
                                        </div>
                                        <span v-else class="text-stone-400">実績なし</span>
                                    </td>
                                    <td class="px-3 text-right text-stone-700 tabular-nums">{{ rank.sales_quantity?.toLocaleString() ?? "—" }}</td>
                                    <td class="px-4">
                                        <span
                                            v-if="changeOf(rank)"
                                            class="rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap"
                                            :class="{
                                                'bg-stone-100 text-stone-600': changeOf(rank)!.tone === 'keep',
                                                'bg-stone-900 font-semibold text-white': changeOf(rank)!.tone === 'add',
                                                'border border-red-200 font-semibold text-red-700': changeOf(rank)!.tone === 'remove',
                                            }"
                                            data-testid="change"
                                            >{{ changeOf(rank)!.label }}</span
                                        >
                                    </td>
                                </tr>
                                <tr v-if="rank.item_no === lastCandidateItemNo" aria-hidden="true">
                                    <td colspan="7" class="h-0 border-t border-dashed border-stone-400 p-0">
                                        <span class="relative -top-2 ml-4 bg-white px-1.5 text-[10px] text-stone-500">ここまで上位{{ candidateRank }}位</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <form class="sticky bottom-4 flex flex-col gap-2 rounded-xl border border-stone-300 bg-white px-4 py-3 shadow-lg" @submit.prevent="confirm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="flex flex-col gap-0.5 sm:w-64" data-testid="selection-summary">
                        <p class="text-[13px] text-stone-900">
                            選択中 <span class="text-base font-semibold tabular-nums">{{ selected.size }}</span> 品番<span class="text-stone-500"> ／ 最大{{ maxItems }}品番</span>
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
                        class="h-10 min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none"
                    />
                    <button
                        type="submit"
                        class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold whitespace-nowrap text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!canConfirm"
                    >
                        {{ isConfirming ? "確定中…" : "この内容で確定" }}
                    </button>
                </div>
                <p v-if="confirmError" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ confirmError }}</p>
                <p v-if="confirmedMessage" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ confirmedMessage }}</p>
            </form>

            <div v-if="isHistoryOpen" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" data-testid="history-backdrop" @click.self="closeHistory">
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="history-title"
                    class="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
                >
                    <header class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                        <h2 id="history-title" class="text-base font-bold text-stone-900">確定履歴</h2>
                        <button
                            ref="historyCloseButton"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                            aria-label="閉じる"
                            @click="closeHistory"
                        >
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <path d="m5 5 10 10M15 5 5 15" />
                            </svg>
                        </button>
                    </header>
                    <div class="overflow-y-auto">
                        <ItemSelectionHistory />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
