<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { useRoute, useRouter, type LocationQuery } from "vue-router";
import { fetchItems, type ItemQuery } from "@/api/items";
import { ApiError } from "@/api/client";
import StatusBadge, { type BadgeTone } from "@/components/ui/StatusBadge.vue";
import CrossWalkerSyncPanel from "@/components/CrossWalkerSyncPanel.vue";
import type { ActiveStatus, Item, Paginated } from "@/types/api";

/**
 * 品番・SKU（決定記録 K-017：商品データの状態確認）。CrossWalker からの取得状況と、取得した品番・所属SKUを確認する。
 * 検索条件は URL のクエリに持たせ、再読み込みしても同じ条件で表示する。
 */

type StatusFilter = ActiveStatus | "all";

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
    { value: "all", label: "すべて" },
    { value: "active", label: "有効" },
    { value: "inactive", label: "無効" },
];

const route = useRoute();
const router = useRouter();

const result = ref<Paginated<Item> | null>(null);
const isLoading = ref(false);
const loadError = ref<ApiError | null>(null);
const expandedItemIds = ref(new Set<number>());
const form = reactive({ keyword: "" });

function queryFromRoute(query: LocationQuery): Required<Pick<ItemQuery, "keyword" | "status" | "page">> {
    const status = STATUS_FILTERS.some((filter) => filter.value === query.status) ? (query.status as StatusFilter) : "all";
    const page = Number(query.page);
    return {
        keyword: typeof query.keyword === "string" ? query.keyword : "",
        status,
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
}

const currentQuery = computed(() => queryFromRoute(route.query));
const hasFilter = computed(() => currentQuery.value.keyword !== "" || currentQuery.value.status !== "all");

let latestRequest = 0;

async function load(): Promise<void> {
    const requestId = ++latestRequest;
    isLoading.value = true;
    loadError.value = null;
    try {
        const { keyword, status, page } = currentQuery.value;
        const response = await fetchItems({ keyword: keyword || undefined, status, page });
        if (requestId === latestRequest) {
            result.value = response;
            expandedItemIds.value = new Set();
        }
    } catch (error) {
        if (requestId === latestRequest) {
            loadError.value = error instanceof ApiError ? error : new ApiError("一覧を表示できませんでした。", null);
        }
    } finally {
        if (requestId === latestRequest) {
            isLoading.value = false;
        }
    }
}

watch(
    () => route.query,
    (query) => {
        form.keyword = queryFromRoute(query).keyword;
        void load();
    },
    { immediate: true },
);

function updateQuery(changes: Partial<ItemQuery>): void {
    const next = { ...currentQuery.value, ...changes };
    void router.push({
        query: {
            ...(next.keyword ? { keyword: next.keyword } : {}),
            ...(next.status !== "all" ? { status: next.status } : {}),
            ...(next.page > 1 ? { page: String(next.page) } : {}),
        },
    });
}

function search(): void {
    updateQuery({ keyword: form.keyword.trim(), page: 1 });
}

function toggleExpanded(itemId: number): void {
    const next = new Set(expandedItemIds.value);
    if (next.has(itemId)) {
        next.delete(itemId);
    } else {
        next.add(itemId);
    }
    expandedItemIds.value = next;
}

function statusTone(status: ActiveStatus | null): BadgeTone {
    return status === "active" ? "positive" : "neutral";
}

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });

function formatDateTime(value: string | null): string {
    return value ? dateTimeFormat.format(new Date(value)) : "—";
}

const validationMessage = computed(() => (loadError.value?.isValidationError ? (Object.values(loadError.value.errors).flat()[0] ?? loadError.value.message) : null));
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-bold tracking-tight text-stone-900">品番・SKU</h1>
            <p class="text-sm text-stone-500">CrossWalker から取得した品番と、所属するSKUを確認します。品番を商品名として扱います。</p>
        </header>

        <CrossWalkerSyncPanel @synced="load" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form class="flex w-full gap-2 sm:max-w-md" role="search" @submit.prevent="search">
                <label for="item-keyword" class="sr-only">品番・SKUコードで検索</label>
                <input
                    id="item-keyword"
                    v-model="form.keyword"
                    type="search"
                    placeholder="品番・SKUコードで検索"
                    class="min-w-0 flex-1 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm placeholder:text-stone-400 focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none"
                />
                <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">検索</button>
            </form>

            <div class="inline-flex self-start rounded-md border border-stone-300 bg-white p-0.5 sm:self-auto" role="group" aria-label="状態で絞り込み">
                <button
                    v-for="filter in STATUS_FILTERS"
                    :key="filter.value"
                    type="button"
                    class="rounded px-3 py-1.5 text-sm"
                    :class="currentQuery.status === filter.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100'"
                    :aria-pressed="currentQuery.status === filter.value"
                    @click="updateQuery({ status: filter.value, page: 1 })"
                >
                    {{ filter.label }}
                </button>
            </div>
        </div>

        <p v-if="validationMessage" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ validationMessage }}</p>

        <section class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
            <div v-if="loadError && !loadError.isValidationError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">一覧を表示できませんでした。</p>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result && isLoading" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="result && result.data.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ hasFilter ? "条件に一致する品番はありません。" : "品番がまだ登録されていません。「CrossWalkerから取得」を押すと、ここに表示されます。" }}
            </div>

            <div v-else-if="result" class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                        <tr>
                            <th scope="col" class="w-10 px-3 py-2.5"><span class="sr-only">SKUを表示</span></th>
                            <th scope="col" class="px-3 py-2.5">品番</th>
                            <th scope="col" class="px-3 py-2.5">ブランド</th>
                            <th scope="col" class="px-3 py-2.5">カテゴリー</th>
                            <th scope="col" class="px-3 py-2.5">親ASIN</th>
                            <th scope="col" class="px-3 py-2.5 text-right">SKU数</th>
                            <th scope="col" class="px-3 py-2.5">状態</th>
                            <th scope="col" class="px-3 py-2.5 whitespace-nowrap">CrossWalker更新日時</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <template v-for="item in result.data" :key="item.id">
                            <tr class="hover:bg-stone-50" :class="expandedItemIds.has(item.id) ? 'bg-stone-50' : ''">
                                <td class="px-3 py-2.5">
                                    <button
                                        type="button"
                                        class="flex size-6 items-center justify-center rounded text-stone-500 hover:bg-stone-200 hover:text-stone-900"
                                        :aria-expanded="expandedItemIds.has(item.id)"
                                        :aria-controls="`skus-${item.id}`"
                                        :aria-label="`${item.item_no} のSKUを${expandedItemIds.has(item.id) ? '閉じる' : '表示'}`"
                                        @click="toggleExpanded(item.id)"
                                    >
                                        <svg
                                            class="size-4 transition-transform"
                                            :class="expandedItemIds.has(item.id) ? 'rotate-90' : ''"
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
                                <td class="px-3 py-2.5 font-mono font-semibold whitespace-nowrap text-stone-900">{{ item.item_no }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-stone-700">{{ item.brand }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-stone-700">{{ item.category }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <span v-if="item.parent_asin" class="font-mono text-stone-700">{{ item.parent_asin }}</span>
                                    <span v-else class="text-stone-400">未設定</span>
                                </td>
                                <td class="px-3 py-2.5 text-right text-stone-700 tabular-nums">{{ item.skus.length }}</td>
                                <td class="px-3 py-2.5"><StatusBadge :tone="statusTone(item.status)" :label="item.status_label" /></td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-stone-500 tabular-nums">{{ formatDateTime(item.crosswalker_updated_at) }}</td>
                            </tr>
                            <tr v-if="expandedItemIds.has(item.id)" :id="`skus-${item.id}`" class="bg-stone-50">
                                <td />
                                <td colspan="7" class="px-3 pt-1 pb-4">
                                    <div v-if="item.skus.length > 0" class="overflow-x-auto rounded-md border border-stone-200 bg-white">
                                        <table class="w-full text-left text-[13px]" :aria-label="`${item.item_no} のSKU`">
                                            <thead class="border-b border-stone-200 bg-stone-50 text-xs text-stone-500">
                                                <tr>
                                                    <th scope="col" class="px-3 py-2 font-medium">SKUコード</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">子ASIN</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">TQ品番</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">TQカラーNo</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">TQサイズ</th>
                                                    <th scope="col" class="px-3 py-2 font-medium">状態</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-stone-100">
                                                <tr v-for="sku in item.skus" :key="sku.id">
                                                    <td class="px-3 py-2 font-mono font-semibold whitespace-nowrap text-stone-900">{{ sku.sku_code }}</td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <span v-if="sku.child_asin" class="font-mono text-stone-700">{{ sku.child_asin }}</span>
                                                        <span v-else class="text-stone-400">未設定</span>
                                                    </td>
                                                    <td class="px-3 py-2 font-mono whitespace-nowrap text-stone-700">{{ sku.tq_item_no ?? "—" }}</td>
                                                    <td class="px-3 py-2 font-mono text-stone-700">{{ sku.tq_color_no ?? "—" }}</td>
                                                    <td class="px-3 py-2 font-mono text-stone-700">{{ sku.tq_size ?? "—" }}</td>
                                                    <td class="px-3 py-2">
                                                        <StatusBadge v-if="sku.status" :tone="statusTone(sku.status)" :label="sku.status_label ?? ''" />
                                                        <span v-else class="text-xs text-stone-400">CrossWalker未登録</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p v-else class="py-2 text-[13px] text-stone-400">所属するSKUはありません。</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <footer v-if="result && result.meta.total > 0" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1 || isLoading"
                        @click="updateQuery({ page: result.meta.current_page - 1 })"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page >= result.meta.last_page || isLoading"
                        @click="updateQuery({ page: result.meta.current_page + 1 })"
                    >
                        次へ
                    </button>
                </div>
            </footer>
        </section>
    </div>
</template>
