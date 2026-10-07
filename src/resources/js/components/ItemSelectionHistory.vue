<script setup lang="ts">
import { onMounted, ref } from "vue";
import { fetchItemSelections } from "@/api/itemSelections";
import type { ItemSelection, Paginated } from "@/types/api";

/**
 * 対象品番の確定の履歴（バックログ B-005、決定記録 K-030）。新しい順に、前回からの変更とメモを表示し、
 * 開くと確定時の順位・売上金額と、外した品番を確認できる。
 */

const result = ref<Paginated<ItemSelection> | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const expandedIds = ref(new Set<number>());

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const formatDate = (value: string): string => value.replaceAll("-", "/");

let latestRequest = 0;

async function load(page = 1): Promise<void> {
    const requestId = ++latestRequest;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchItemSelections(page);
        if (requestId === latestRequest) {
            result.value = response;
            expandedIds.value = new Set();
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

function toggle(id: number): void {
    const next = new Set(expandedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    expandedIds.value = next;
}

function isLatest(selection: ItemSelection): boolean {
    return result.value?.meta.current_page === 1 && result.value.data[0]?.id === selection.id;
}
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
        <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <p class="text-sm text-stone-600">確定の履歴を表示できませんでした。</p>
            <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load()">再読み込み</button>
        </div>

        <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="result.meta.total === 0" class="px-6 py-16 text-center text-sm text-stone-500">まだ一度も確定していません。</div>

        <template v-else>
            <div class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                        <tr>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">内容を表示</span></th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">確定日時</th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">確定した人</th>
                            <th scope="col" class="px-3 py-2.5 text-right">品番数</th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">前回からの変更</th>
                            <th scope="col" class="px-3 py-2.5">メモ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <template v-for="selection in result.data" :key="selection.id">
                            <tr class="cursor-pointer hover:bg-stone-50" :class="expandedIds.has(selection.id) ? 'bg-stone-50' : ''" @click="toggle(selection.id)">
                                <td class="px-3 py-2.5">
                                    <button
                                        type="button"
                                        class="flex size-6 items-center justify-center rounded text-stone-500 hover:bg-stone-200 hover:text-stone-900"
                                        :aria-expanded="expandedIds.has(selection.id)"
                                        :aria-controls="`selection-${selection.id}`"
                                        :aria-label="`${formatDateTime(selection.confirmed_at)} の確定内容を${expandedIds.has(selection.id) ? '閉じる' : '表示'}`"
                                        @click.stop="toggle(selection.id)"
                                    >
                                        <svg
                                            class="size-4 transition-transform"
                                            :class="expandedIds.has(selection.id) ? 'rotate-90' : ''"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="m8 5 5 5-5 5" />
                                        </svg>
                                    </button>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-stone-900 tabular-nums">
                                    {{ formatDateTime(selection.confirmed_at) }}
                                    <span v-if="isLatest(selection)" class="ml-2 rounded-full bg-stone-900 px-2 py-0.5 text-xs font-semibold text-white">今の対象</span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap" data-testid="confirmed-by">
                                    <span v-if="selection.confirmed_by" class="text-stone-700">{{ selection.confirmed_by }}</span>
                                    <span v-else class="text-stone-400">記録なし</span>
                                </td>
                                <td class="px-3 py-2.5 text-right text-stone-700 tabular-nums">{{ selection.items.length }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-stone-700 tabular-nums" data-testid="changes">
                                    <template v-if="!selection.changes">初回</template>
                                    <template v-else-if="selection.changes.added.length === 0 && selection.changes.removed.length === 0">変更なし</template>
                                    <template v-else>追加 {{ selection.changes.added.length }}・外す {{ selection.changes.removed.length }}</template>
                                </td>
                                <td class="px-3 py-2.5 text-stone-600">
                                    <span v-if="selection.note">{{ selection.note }}</span>
                                    <span v-else class="text-stone-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="expandedIds.has(selection.id)" :id="`selection-${selection.id}`" class="bg-stone-50">
                                <td />
                                <td colspan="5" class="px-3 pt-1 pb-4">
                                    <p class="mb-2 text-xs text-stone-500 tabular-nums">売上の集計期間：{{ formatDate(selection.ranking_from) }} 〜 {{ formatDate(selection.ranking_to) }}</p>
                                    <div class="overflow-x-auto rounded-md border border-stone-200 bg-white">
                                        <table class="w-full text-left text-[13px]" :aria-label="`${formatDateTime(selection.confirmed_at)} に確定した品番`">
                                            <thead class="border-b border-stone-200 bg-stone-50 text-xs text-stone-500">
                                                <tr>
                                                    <th scope="col" class="px-3 py-2 text-right font-medium whitespace-nowrap">確定時の順位</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">品番</th>
                                                    <th scope="col" class="px-3 py-2 text-right font-medium whitespace-nowrap">確定時の売上金額</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">前回から</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-stone-100">
                                                <tr v-for="item in selection.items" :key="item.item_no">
                                                    <td class="px-3 py-2 text-right text-stone-700 tabular-nums">{{ item.sales_rank ?? "—" }}</td>
                                                    <td class="px-3 py-2 font-mono font-semibold whitespace-nowrap text-stone-900">
                                                        {{ item.item_no }}
                                                        <span v-if="!item.exists_in_crosswalker" class="ml-1 font-sans text-xs font-normal text-amber-700">CrossWalkerから消えた品番</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                        <span v-if="item.sales_amount !== null" class="text-stone-700">¥{{ item.sales_amount.toLocaleString() }}</span>
                                                        <span v-else class="text-stone-400">実績なし</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-xs text-stone-600">{{ selection.changes?.added.includes(item.item_no) ? "追加" : "" }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p v-if="selection.changes && selection.changes.removed.length > 0" class="mt-2 text-[13px] text-stone-600" data-testid="removed">
                                        外した品番：<span class="font-mono">{{ selection.changes.removed.join("、") }}</span>
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <footer v-if="result.meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1 || isLoading"
                        @click="load(result.meta.current_page - 1)"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
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
