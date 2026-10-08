<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { useRoute, useRouter, type LocationQuery } from "vue-router";
import { fetchItems, type ItemQuery } from "@/api/items";
import { ApiError } from "@/api/client";
import SyncStatusPanel from "@/components/SyncStatusPanel.vue";
import UnregisteredSkuList from "@/components/UnregisteredSkuList.vue";
import { fetchLatestCrossWalkerSync, runCrossWalkerSync } from "@/api/crosswalkerSyncs";
import type { ActiveStatus, CrossWalkerSync, Item, Paginated } from "@/types/api";

/**
 * 品番・SKU（決定記録 K-017：商品データの状態確認）。CrossWalker からの取得状況と、取得した品番・所属SKUを確認する。
 * 品番に属していないSKU（B-010）は別のタブに表示し、件数はタブに常に出す。
 * 表示中のタブと検索条件は URL のクエリに持たせ、再読み込みしても同じ条件で表示する。
 * 見た目は在庫推移の画面（K-047）とそろえる。品番の行をクリックすると、所属するSKUを開く。
 */

type StatusFilter = ActiveStatus | "all";
type Tab = "items" | "unregistered";

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
const unregisteredTotal = ref<number | null>(null);
const unregisteredListKey = ref(0);

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
const activeTab = computed<Tab>(() => (route.query.tab === "unregistered" ? "unregistered" : "items"));
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
    () => JSON.stringify(currentQuery.value),
    () => {
        form.keyword = currentQuery.value.keyword;
        void load();
    },
    { immediate: true },
);

function selectTab(tab: Tab): void {
    const query = { ...route.query };
    delete query.tab;
    void router.push({ query: tab === "unregistered" ? { ...query, tab } : query });
}

function reloadAfterSync(): void {
    void load();
    unregisteredListKey.value++;
}

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
    clearTimeout(searchTimer);
    if (form.keyword.trim() !== currentQuery.value.keyword) {
        updateQuery({ keyword: form.keyword.trim(), page: 1 });
    }
}

// 入力が止まってから検索する（Enter ならすぐ検索する）
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => form.keyword,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(search, 300);
    },
);
onBeforeUnmount(() => clearTimeout(searchTimer));

function toggleExpanded(itemId: number): void {
    const next = new Set(expandedItemIds.value);
    if (next.has(itemId)) {
        next.delete(itemId);
    } else {
        next.add(itemId);
    }
    expandedItemIds.value = next;
}

/** 品番に属する廃番のSKUの数（K-053）。 */
function discontinuedCount(item: Item): number {
    return item.skus.filter((sku) => sku.status === "inactive").length;
}

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });

function formatDateTime(value: string | null): string {
    return value ? dateTimeFormat.format(new Date(value)) : "—";
}

function summarizeSync(sync: CrossWalkerSync): string {
    const changes = [
        sync.added_item_count ? `追加 ${sync.added_item_count}件` : null,
        sync.removed_item_count ? `削除 ${sync.removed_item_count}件` : null,
        sync.detached_sku_count ? `品番から外れたSKU ${sync.detached_sku_count}件` : null,
    ].filter(Boolean);
    return `品番 ${sync.item_count}件・SKU ${sync.sku_count}件${changes.length ? `（${changes.join("、")}）` : ""}`;
}

const validationMessage = computed(() => (loadError.value?.isValidationError ? (Object.values(loadError.value.errors).flat()[0] ?? loadError.value.message) : null));
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">商品 / 品番・SKU</p>
            <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">品番・SKU</h1>
            <p class="text-[13px] text-stone-600">CrossWalker から取得した品番と、所属するSKUを確認します。品番を商品名として扱います。</p>
        </header>

        <SyncStatusPanel
            source="CrossWalker"
            schedule="毎朝6時"
            subject="品番とSKU"
            :load-state="fetchLatestCrossWalkerSync"
            :run-sync="runCrossWalkerSync"
            :summarize="summarizeSync"
            @synced="reloadAfterSync"
        />

        <div class="inline-flex gap-0.5 self-start rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="表示する一覧">
            <button
                id="tab-items"
                type="button"
                role="tab"
                class="inline-flex h-8 items-center rounded-[7px] px-3.5 text-[13px]"
                :class="activeTab === 'items' ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                :aria-selected="activeTab === 'items'"
                aria-controls="panel-items"
                @click="selectTab('items')"
            >
                品番
            </button>
            <button
                id="tab-unregistered"
                type="button"
                role="tab"
                class="inline-flex h-8 items-center gap-2 rounded-[7px] px-3.5 text-[13px]"
                :class="activeTab === 'unregistered' ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                :aria-selected="activeTab === 'unregistered'"
                aria-controls="panel-unregistered"
                @click="selectTab('unregistered')"
            >
                未登録SKU
                <span
                    v-if="unregisteredTotal !== null"
                    class="rounded-full px-1.5 py-px text-[11px] font-semibold tabular-nums"
                    :class="unregisteredTotal > 0 ? 'bg-amber-100 text-amber-800' : activeTab === 'unregistered' ? 'bg-white/15 text-stone-200' : 'bg-white text-stone-500'"
                    data-testid="unregistered-total"
                >
                    {{ unregisteredTotal }}件
                </span>
            </button>
        </div>

        <UnregisteredSkuList
            v-show="activeTab === 'unregistered'"
            id="panel-unregistered"
            :key="unregisteredListKey"
            role="tabpanel"
            aria-labelledby="tab-unregistered"
            @loaded="unregisteredTotal = $event"
        />

        <section v-show="activeTab === 'items'" id="panel-items" class="overflow-hidden rounded-xl border border-stone-200 bg-white" role="tabpanel" aria-labelledby="tab-items" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="状態で絞り込み">
                    <button
                        v-for="filter in STATUS_FILTERS"
                        :key="filter.value"
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="currentQuery.status === filter.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                        :aria-pressed="currentQuery.status === filter.value"
                        @click="updateQuery({ status: filter.value, page: 1 })"
                    >
                        {{ filter.label }}
                    </button>
                </div>
                <form class="w-full lg:w-72" role="search" @submit.prevent="search">
                    <label class="flex h-9 items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900">
                        <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="m10.5 10.5 3 3" />
                        </svg>
                        <span class="sr-only">品番・SKUコードで検索</span>
                        <input
                            id="item-keyword"
                            v-model="form.keyword"
                            type="search"
                            placeholder="品番・SKUコードで検索"
                            class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400"
                        />
                    </label>
                </form>
            </div>

            <p v-if="validationMessage" class="border-b border-stone-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">{{ validationMessage }}</p>

            <div v-if="loadError && !loadError.isValidationError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">一覧を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result && isLoading" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="result && result.data.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ hasFilter ? "条件に一致する品番はありません。" : "品番がまだ登録されていません。上の「取得実行」を押すと、ここに表示されます。" }}
            </div>

            <div v-else-if="result" class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-[52rem] border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="w-12 py-2.5 pl-4"><span class="sr-only">SKUを表示</span></th>
                            <th scope="col" class="px-3 py-2.5">品番</th>
                            <th scope="col" class="px-3 py-2.5">親ASIN</th>
                            <th scope="col" class="px-3 py-2.5 text-right">SKU数</th>
                            <th scope="col" class="px-3 py-2.5">状態</th>
                            <th scope="col" class="px-4 py-2.5 whitespace-nowrap">CrossWalker更新日時</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="item in result.data" :key="item.id">
                            <tr
                                class="h-11 cursor-pointer [&>*]:border-t [&>*]:border-stone-100"
                                :class="expandedItemIds.has(item.id) ? 'bg-stone-50' : 'hover:bg-stone-50'"
                                @click="toggleExpanded(item.id)"
                            >
                                <td class="py-0 pl-4">
                                    <button
                                        type="button"
                                        class="flex size-7 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-200 hover:text-stone-900"
                                        :aria-expanded="expandedItemIds.has(item.id)"
                                        :aria-controls="`skus-${item.id}`"
                                        :aria-label="`${item.item_no} のSKUを${expandedItemIds.has(item.id) ? '閉じる' : '表示'}`"
                                        @click.stop="toggleExpanded(item.id)"
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
                                <td class="px-3 whitespace-nowrap">
                                    <span class="font-mono text-sm font-medium text-stone-900">{{ item.item_no }}</span>
                                    <span class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ item.brand }}・{{ item.category }}</span>
                                </td>
                                <td class="px-3 whitespace-nowrap">
                                    <span v-if="item.parent_asin" class="font-mono text-[12.5px] text-stone-700">{{ item.parent_asin }}</span>
                                    <span v-else class="text-stone-400">未設定</span>
                                </td>
                                <td class="px-3 text-right whitespace-nowrap tabular-nums">
                                    <span class="text-stone-900">{{ item.skus.length }}</span>
                                    <span v-if="discontinuedCount(item) > 0" class="ml-1 text-[11px] text-stone-500">（廃番 {{ discontinuedCount(item) }}）</span>
                                </td>
                                <td class="px-3">
                                    <span class="rounded-full px-2 py-0.5 text-[11px]" :class="item.status === 'active' ? 'border border-stone-200 text-stone-600' : 'bg-stone-100 text-stone-500'">{{
                                        item.status_label
                                    }}</span>
                                </td>
                                <td class="px-4 whitespace-nowrap text-stone-500 tabular-nums">{{ formatDateTime(item.crosswalker_updated_at) }}</td>
                            </tr>
                            <tr v-if="expandedItemIds.has(item.id)" :id="`skus-${item.id}`" class="bg-stone-50">
                                <td />
                                <td colspan="5" class="px-3 pt-1 pb-4">
                                    <div v-if="item.skus.length > 0" class="overflow-x-auto rounded-lg border border-stone-200 bg-white">
                                        <table class="w-full border-separate border-spacing-0 text-left text-[13px]" :aria-label="`${item.item_no} のSKU`">
                                            <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-200">
                                                <tr>
                                                    <th scope="col" class="px-3 py-2">SKUコード</th>
                                                    <th scope="col" class="px-3 py-2">子ASIN</th>
                                                    <th scope="col" class="px-3 py-2">TQ品番</th>
                                                    <th scope="col" class="px-3 py-2">TQカラーNo</th>
                                                    <th scope="col" class="px-3 py-2">TQサイズ</th>
                                                    <th scope="col" class="px-3 py-2">状態</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="(sku, index) in item.skus"
                                                    :key="sku.id"
                                                    :class="[index > 0 ? '[&>*]:border-t [&>*]:border-stone-100' : '', sku.status === 'inactive' ? 'text-stone-400' : '']"
                                                >
                                                    <td class="px-3 py-2 font-mono text-[12.5px] whitespace-nowrap" :class="sku.status === 'inactive' ? '' : 'text-stone-900'">{{ sku.sku_code }}</td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <span v-if="sku.child_asin" class="font-mono text-[12.5px]" :class="sku.status === 'inactive' ? '' : 'text-stone-700'">{{
                                                            sku.child_asin
                                                        }}</span>
                                                        <span v-else class="text-stone-400">未設定</span>
                                                    </td>
                                                    <td class="px-3 py-2 font-mono text-[12.5px] whitespace-nowrap">{{ sku.tq_item_no ?? "—" }}</td>
                                                    <td class="px-3 py-2 font-mono text-[12.5px]">{{ sku.tq_color_no ?? "—" }}</td>
                                                    <td class="px-3 py-2 font-mono text-[12.5px]">{{ sku.tq_size ?? "—" }}</td>
                                                    <td class="px-3 py-2">
                                                        <span
                                                            v-if="sku.status"
                                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                                            :class="sku.status === 'active' ? 'border border-stone-200 text-stone-600' : 'bg-stone-100 text-stone-500'"
                                                            >{{ sku.status_label }}</span
                                                        >
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

            <footer v-if="result && result.meta.total > 0" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-[13px] text-stone-600">
                <p class="tabular-nums">{{ result.meta.total }}件中 {{ result.meta.from }}–{{ result.meta.to }}件</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="result.meta.current_page <= 1 || isLoading"
                        @click="updateQuery({ page: result.meta.current_page - 1 })"
                    >
                        前へ
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
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
