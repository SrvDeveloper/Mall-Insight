<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { fetchSalesActuals } from "@/api/actuals";
import ActualsGraph from "@/components/actuals/ActualsGraph.vue";
import ActualsTable from "@/components/actuals/ActualsTable.vue";
import { buildActualRows, salesColumns, type ActualColumn, type ActualItemRows, type ActualMeasure } from "@/components/actuals/actualRows";
import type { SalesActualsResponse, TrendScope } from "@/types/api";

/**
 * 品番別売上（バックログ B-124）。左メニュー「販売実績」の画面。対象品番のSKUについて、月ごとの販売数（直近12か月＋今月）を表・グラフで見る。
 * 販売実績は日ごとの販売数や販売数の色分けなど、詳しく見るための機能を足していくため、在庫実績とは画面を分ける（K-075）。
 * 全体・Amazon・BOSSの切り替え、見せ方と色は在庫推移の画面（K-047・K-074）とそろえる。販売実績を取り込んでいない月は0とせず「—」で示す（原則2）。
 * 数量と金額（取り込んだ販売実績の金額の合計、税込、千円）を切り替えられる。選んだ在庫・見せ方・数量と金額は URL に持たせる。
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
const measure = computed<ActualMeasure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));

const MEASURES: { value: ActualMeasure; label: string }[] = [
    { value: "quantity", label: "数量" },
    { value: "amount", label: "金額" },
];

function updateQuery(changes: { scope?: TrendScope; view?: ActualView; measure?: ActualMeasure }): void {
    const next = { scope: changes.scope ?? scope.value, view: changes.view ?? view.value, measure: changes.measure ?? measure.value };
    void router.replace({
        query: {
            ...route.query,
            scope: next.scope === "total" ? undefined : next.scope,
            measure: next.measure === "quantity" ? undefined : next.measure,
            view: next.view === "table" ? undefined : next.view,
        },
    });
}

const result = shallowRef<SalesActualsResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const keyword = ref("");

async function load(): Promise<void> {
    const requestedScope = scope.value;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchSalesActuals(requestedScope);
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

const columns = computed<ActualColumn[]>(() => (result.value ? salesColumns(result.value.meta) : []));
const itemRows = computed<ActualItemRows[]>(() => buildActualRows(result.value?.data ?? [], "sales", measure.value));

const visibleItems = computed<ActualItemRows[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    if (word === "") {
        return itemRows.value;
    }
    return itemRows.value
        .map((group) => (group.item.item_no.toLowerCase().includes(word) ? group : { ...group, rows: group.rows.filter((row) => row.sku.sku_code.toLowerCase().includes(word)) }))
        .filter((group) => group.rows.length > 0);
});

const summaryLabel = computed(() => (measure.value === "amount" ? "期間計（千円）" : "期間計"));
const notSoldLabel = computed(() => `${result.value?.meta.scope_label ?? ""}では販売していません`);

const formatDate = (value: string): string => value.replaceAll("-", "/");

/** 販売実績を取り込んだ期間（選んだ在庫のモール）。 */
const rangeNote = computed(() =>
    (result.value?.meta.channels ?? [])
        .filter((channel) => scope.value === "total" || channel.channel === scope.value)
        .map((channel) => (channel.sales_data_from ? `${channel.label} ${formatDate(channel.sales_data_from)}〜${formatDate(channel.sales_data_to!)}` : `${channel.label} 未取込`))
        .join("・"),
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">販売実績 / 品番別売上</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">品番別売上</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="description">取り込んだ販売実績の、月ごとの販売数です（直近12か月と今月）。Amazon・BOSSは販売実績の出荷倉庫で分けます。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- 地（stone-100）の上に置くため、白い面と枠で浮かせる（在庫推移の切り替えとそろえる） -->
                <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="モール">
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
            <p class="text-sm text-stone-600">販売実績を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="!result.meta.selection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900">
            対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定すると、ここに販売実績が表示されます。
        </div>

        <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="数量と金額">
                        <button
                            v-for="option in MEASURES"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="measure === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                            :aria-pressed="measure === option.value"
                            :data-testid="`measure-${option.value}`"
                            @click="updateQuery({ measure: option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
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
                <span class="inline-flex items-center gap-1.5"><span class="text-stone-300">—</span>販売実績を取り込んでいない月</span>
                <span class="text-stone-400">期間計は表の13か月の合計です</span>
                <span v-if="measure === 'amount'" class="text-stone-500" data-testid="amount-note">金額は取り込んだ販売実績の金額（税込）の合計で、千円単位です</span>
            </div>

            <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ keyword.trim() === "" ? "対象品番にSKUがありません。" : "条件に一致するSKUはありません。" }}
            </div>
            <ActualsTable v-else-if="view === 'table'" :items="visibleItems" :columns="columns" :summary-label="summaryLabel" :not-sold-label="notSoldLabel" />
            <ActualsGraph v-else :items="visibleItems" :columns="columns" kind="sales" :summary-label="summaryLabel" :not-sold-label="notSoldLabel" />
        </section>
    </div>
</template>
