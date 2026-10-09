<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchStockActuals } from "@/api/actuals";
import ActualsGraph from "@/components/actuals/ActualsGraph.vue";
import ActualsTable from "@/components/actuals/ActualsTable.vue";
import { buildActualRows, stockColumns, type ActualColumn, type ActualItemRows } from "@/components/actuals/actualRows";
import type { StockActualsResponse, TrendScope } from "@/types/api";

/**
 * 在庫実績（バックログ B-124）。対象品番のSKUについて、日ごとの在庫数（直近31日）を表・グラフで見る。
 * 全体（6区分の合計）・Amazon・BOSSの切り替え、見せ方と色は在庫推移の画面（K-047・K-074）とそろえる。
 * 在庫を取得していない日は0とせず「—」で示し（原則2）、在庫0は在庫不足と同じ赤い札で示す。選んだ在庫・見せ方は URL に持たせる。
 */

type ActualView = "table" | "graph";

const SCOPES: { value: TrendScope; label: string }[] = [
    { value: "total", label: "全体" },
    { value: "amazon", label: "Amazon" },
    { value: "boss", label: "BOSS" },
];

const VIEWS: { value: ActualView; label: string }[] = [
    { value: "table", label: "表" },
    { value: "graph", label: "グラフ" },
];

const route = useRoute();
const router = useRouter();

const scope = computed<TrendScope>(() => (route.query.scope === "amazon" || route.query.scope === "boss" ? route.query.scope : "total"));
const view = computed<ActualView>(() => (route.query.view === "graph" ? "graph" : "table"));

function updateQuery(changes: { scope?: TrendScope; view?: ActualView }): void {
    const next = { scope: changes.scope ?? scope.value, view: changes.view ?? view.value };
    void router.replace({
        query: {
            ...route.query,
            scope: next.scope === "total" ? undefined : next.scope,
            view: next.view === "table" ? undefined : next.view,
        },
    });
}

const result = shallowRef<StockActualsResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const keyword = ref("");

async function load(): Promise<void> {
    const requestedScope = scope.value;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchStockActuals(requestedScope);
        // 読み込み中に在庫を切り替えたときは、古い方の結果を表示しない
        if (requestedScope === scope.value) {
            result.value = response;
        }
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);
watch(scope, () => {
    result.value = null;
    void load();
});

const columns = computed<ActualColumn[]>(() => (result.value ? stockColumns(result.value.meta) : []));
const itemRows = computed<ActualItemRows[]>(() => buildActualRows(result.value?.data ?? [], "stock"));

const visibleItems = computed<ActualItemRows[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    if (word === "") {
        return itemRows.value;
    }
    return itemRows.value
        .map((group) => (group.item.item_no.toLowerCase().includes(word) ? group : { ...group, rows: group.rows.filter((row) => row.sku.sku_code.toLowerCase().includes(word)) }))
        .filter((group) => group.rows.length > 0);
});

const summaryLabel = "最新";
const notSoldLabel = computed(() => `${result.value?.meta.scope_label ?? ""}では販売していません`);

const formatDate = (value: string): string => value.replaceAll("-", "/");

/** 月初在庫に数える在庫の区分の説明。 */
const warehouseNote = computed(() => (scope.value === "total" ? "6区分の合計" : (result.value?.meta.scope_warehouses.map((warehouse) => warehouse.label).join("＋") ?? "")));

/** 在庫を取得した日の数。 */
const rangeNote = computed(() => {
    const dates = result.value?.meta.dates ?? [];
    return dates.length > 0 ? `${formatDate(dates[0]!.date)}〜${formatDate(dates.at(-1)!.date)} のうち ${dates.filter((date) => date.has_data).length}日に在庫を取得` : "";
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 在庫実績</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">在庫実績</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="description">ZeroStockViewから取得した、日ごとの在庫数です（直近31日）。{{ warehouseNote }}です。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- 地（stone-100）の上に置くため、白い面と枠で浮かせる（在庫推移の切り替えとそろえる） -->
                <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="在庫">
                    <button
                        v-for="option in SCOPES"
                        :key="option.value"
                        type="button"
                        role="tab"
                        class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                        :class="scope === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        :aria-selected="scope === option.value"
                        :data-testid="`scope-${option.value}`"
                        @click="updateQuery({ scope: option.value })"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-900 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isLoading"
                    @click="load"
                >
                    <svg class="size-4" :class="isLoading ? 'animate-spin' : ''" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                        <path d="M13.5 2.5v3h-3" />
                    </svg>
                    {{ isLoading ? "読み込み中…" : "再読み込み" }}
                </button>
            </div>
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">在庫実績を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="!result.meta.selection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900">
            対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに在庫実績が表示されます。
        </div>

        <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="見せ方">
                        <button
                            v-for="option in VIEWS"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="view === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                            :aria-pressed="view === option.value"
                            :data-testid="`view-${option.value}`"
                            @click="updateQuery({ view: option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
                <label class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
                    <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <circle cx="7" cy="7" r="4.5" />
                        <path d="m10.5 10.5 3 3" />
                    </svg>
                    <span class="sr-only">品番・SKUで絞り込み</span>
                    <input v-model="keyword" type="search" placeholder="品番・SKUで絞り込み" class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400" />
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
                <span class="tabular-nums" data-testid="range-note">{{ rangeNote }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="text-stone-300">—</span>在庫を取得していない日</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-4.5 rounded-sm bg-red-100 ring-1 ring-red-200 ring-inset" />在庫0</span>
                <span class="text-stone-400">最新は最後に在庫を取得した日の在庫数です</span>
            </div>

            <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ keyword.trim() === "" ? "対象品番にSKUがありません。" : "条件に一致するSKUはありません。" }}
            </div>
            <ActualsTable v-else-if="view === 'table'" :items="visibleItems" :columns="columns" :summary-label="summaryLabel" :not-sold-label="notSoldLabel" />
            <ActualsGraph v-else :items="visibleItems" :columns="columns" kind="stock" :summary-label="summaryLabel" :not-sold-label="notSoldLabel" />
        </section>
    </div>
</template>
