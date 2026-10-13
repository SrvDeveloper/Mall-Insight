<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchStoreSales, type SalesPeriod } from "@/api/actuals";
import SalesActualsToolbar from "@/components/actuals/SalesActualsToolbar.vue";
import SalesChart from "@/components/actuals/SalesChart.vue";
import StoreRankingPanel from "@/components/actuals/StoreRankingPanel.vue";
import StoreSalesTable from "@/components/actuals/StoreSalesTable.vue";
import { chartFormats, salesColumns, storeSeries, type ActualColumn, type ActualMeasure } from "@/components/actuals/actualRows";
import { buildStoreRows } from "@/components/actuals/storeSalesRows";
import type { SalesView } from "@/components/actuals/useSalesActuals";
import type { SalesUnit, StoreSales, StoreSalesProducts, StoreSalesResponse } from "@/types/api";

/**
 * 店舗売上（バックログ B-126）。左メニュー「販売実績」の画面。楽天市場・Yahoo!ショッピング・au PAY マーケット・Amazon の店舗ごとの売上を、
 * 月ごと（直近12か月＋今月）か日ごと（直近30日）に表・グラフで見る。期間の指定と前月比・前年比のツールチップは品番別売上とそろえる（K-080・K-082）。
 * 商品は全商品（CrossWalkerに未登録のSKUを含む）と対象品番だけを切り替えられる。初めは店舗の売上として金額を出す（K-088）。
 * 販売実績を取り込んでいない月・日は0とせず「—」で示す（原則2）。表では合計の行の (i) を押すとグラフと同じ内訳を出し（K-094）、欄を押すと
 * その店舗・月（日）の売上上位の品番を表の下に出す（K-091・K-092・K-096）。
 * 選んだ商品・月ごとと日ごと・数量と金額・見せ方・期間は URL に持たせる。
 */

const PRODUCTS: { value: StoreSalesProducts; label: string }[] = [
    { value: "all", label: "全商品" },
    { value: "target", label: "対象品番" },
];

const route = useRoute();
const router = useRouter();

const products = computed<StoreSalesProducts>(() => (route.query.products === "target" ? "target" : "all"));
const unit = computed<SalesUnit>(() => (route.query.unit === "day" ? "day" : "month"));
const measure = computed<ActualMeasure>(() => (route.query.measure === "quantity" ? "quantity" : "amount"));
const view = computed<SalesView>(() => (route.query.view === "graph" ? "graph" : "table"));
const period = computed<SalesPeriod>(() => (typeof route.query.from === "string" && typeof route.query.to === "string" ? { from: route.query.from, to: route.query.to } : null));

function updateQuery(changes: { products?: StoreSalesProducts; unit?: SalesUnit; measure?: ActualMeasure; view?: SalesView; period?: SalesPeriod }): void {
    const next = { products: changes.products ?? products.value, unit: changes.unit ?? unit.value, measure: changes.measure ?? measure.value, view: changes.view ?? view.value };
    // 月ごと・日ごとを切り替えたら、期間は初期値に戻す（K-080）
    const nextPeriod = changes.unit && changes.unit !== unit.value ? null : changes.period !== undefined ? changes.period : period.value;
    void router.replace({
        query: {
            ...route.query,
            products: next.products === "all" ? undefined : next.products,
            unit: next.unit === "month" ? undefined : next.unit,
            measure: next.measure === "amount" ? undefined : next.measure,
            view: next.view === "table" ? undefined : next.view,
            from: nextPeriod?.from,
            to: nextPeriod?.to,
        },
    });
}

const result = shallowRef<StoreSalesResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
/** 期間の指定が受け付けられなかったときの理由（前の結果は表示したままにする） */
const periodError = ref<string | null>(null);

const requestKey = computed(() => JSON.stringify([products.value, unit.value, period.value]));

async function load(): Promise<void> {
    const key = requestKey.value;
    isLoading.value = true;
    hasError.value = false;
    periodError.value = null;
    try {
        const response = await fetchStoreSales(unit.value, products.value, period.value);
        // 読み込み中に選択を変えたときは、古い方の結果を表示しない
        if (key === requestKey.value) {
            result.value = response;
        }
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError && (error.errors.from || error.errors.to)) {
            periodError.value = error.errors.from?.[0] ?? error.errors.to?.[0] ?? null;
        } else {
            hasError.value = true;
        }
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);
watch(requestKey, (current, previous) => {
    // 期間だけを変えたときは、結果を消さずに読み込み直す（期間の欄が消えないように）
    const [previousProducts, previousUnit] = JSON.parse(previous) as unknown[];
    const [currentProducts, currentUnit] = JSON.parse(current) as unknown[];
    if (previousProducts !== currentProducts || previousUnit !== currentUnit) {
        result.value = null;
    }
    void load();
});

const columns = computed<ActualColumn[]>(() => (result.value ? salesColumns(result.value.meta) : []));
const rows = computed(() => buildStoreRows(result.value?.data ?? [], measure.value));
const series = computed(() => storeSeries(result.value?.data ?? [], measure.value));
const formats = computed(() => chartFormats(measure.value));
const summaryLabel = computed(() => `期間計${measure.value === "amount" ? "（千円）" : ""}`);
const needsSelection = computed(() => products.value === "target" && result.value !== null && !result.value.meta.selection);

const formatDate = (value: string): string => value.replaceAll("-", "/");

/** 売上上位を開いている欄。 */
const rankingTarget = ref<{ index: number; key: string | null; period: string; label: string; mall: StoreSales["mall"] | null; storeLabel: string } | null>(null);

function openRanking(index: number, key: string | null): void {
    const column = columns.value[index];
    if (!column) {
        return;
    }
    // 出している欄をもう一度押したら閉じる
    if (rankingTarget.value?.index === index && rankingTarget.value.key === key) {
        rankingTarget.value = null;
        return;
    }
    const [year, month, day] = column.key.split("-").map(Number);
    const label = `${year}年${month}月${day ? `${day}日` : ""}${column.current ? (unit.value === "month" ? "（今日まで）" : "") : ""}`;
    const store = result.value?.data.find((item) => item.mall === key);
    rankingTarget.value = { index, key, period: column.key, label, mall: store?.mall ?? null, storeLabel: store?.label ?? "全店舗" };
}

// 商品・単位・期間や見せ方を変えたら閉じる
watch([requestKey, view], () => {
    rankingTarget.value = null;
});

/** 販売実績を取り込んだ期間（BOSS は楽天市場・Yahoo!ショッピング・au PAY マーケット）。 */
const rangeNote = computed(() =>
    (result.value?.meta.channels ?? [])
        .map((channel) => (channel.sales_data_from ? `${channel.label} ${formatDate(channel.sales_data_from)}〜${formatDate(channel.sales_data_to!)}` : `${channel.label} 未取込`))
        .join("・"),
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">販売実績 / 店舗売上</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">店舗売上</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="description">
                    取り込んだ販売実績の、店舗ごとの{{ unit === "day" ? "日ごと" : "月ごと" }}の売上です。{{
                        products === "all" ? "CrossWalkerに未登録のSKUを含む、全商品の売上です。" : "対象品番のSKUだけの売上です。"
                    }}楽天市場・Yahoo!ショッピング・au PAY マーケットはBOSS受注実績から、AmazonはAmazon全注文レポートから集めています。合計の行の (i) を押すと、その{{
                        unit === "day" ? "日" : "月"
                    }}の店舗ごとの内訳と前{{ unit === "day" ? "日" : "月" }}比・前年比を見られます。表の欄を押すと、その店舗・{{ unit === "day" ? "日" : "月" }}の売上上位の品番を見られます。
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- 地（stone-100）の上に置くため、白い面と枠で浮かせる（品番別売上のモールの切り替えとそろえる） -->
                <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="商品">
                    <button
                        v-for="option in PRODUCTS"
                        :key="option.value"
                        type="button"
                        role="tab"
                        class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                        :class="products === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                        :aria-selected="products === option.value"
                        :data-testid="`products-${option.value}`"
                        @click="updateQuery({ products: option.value })"
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
            <p class="text-sm text-stone-600">店舗売上を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="needsSelection" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900" data-testid="needs-selection">
            対象品番がまだ確定されていません。<RouterLink to="/target-items" class="font-semibold underline">対象品番</RouterLink>の画面で確定するか、「全商品」に切り替えてください。
        </div>

        <section v-else class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <SalesActualsToolbar :unit="unit" :measure="measure" :view="view" :range-note="rangeNote" :meta="result.meta" :period-error="periodError" @update="updateQuery" />
            <div v-if="view === 'table'" class="flex flex-col">
                <StoreSalesTable
                    :total="rows.total"
                    :stores="rows.stores"
                    :columns="columns"
                    :summary-label="summaryLabel"
                    :column-width="unit === 'day' ? 80 : 96"
                    :series="series"
                    :format-value="formats.formatValue"
                    :unit="unit"
                    :selected="rankingTarget ? { index: rankingTarget.index, key: rankingTarget.key } : null"
                    @pick="openRanking"
                />
                <p v-if="rows.total.cells.some((cell) => cell.excludes)" class="border-t border-stone-200 px-4 py-2.5 text-xs text-stone-500" data-testid="excludes-note">
                    ※ 販売実績を取り込んでいない店舗を除いた合計です
                </p>
                <StoreRankingPanel :target="rankingTarget" :unit="unit" :products="products" :measure="measure" @close="rankingTarget = null" />
            </div>
            <div v-else class="flex flex-col gap-4 p-4" data-testid="store-sales-chart">
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="店舗ごとの期間計">
                    <li v-for="row in rows.stores" :key="row.key" class="flex flex-col gap-1 rounded-lg border border-stone-200 px-3 py-2.5" data-testid="store-tile">
                        <span class="flex items-center gap-1.5 text-xs text-stone-600"
                            ><span class="size-2.5 rounded-sm" :style="{ backgroundColor: row.color ?? undefined }" aria-hidden="true" />{{ row.label }}</span
                        >
                        <span class="flex items-baseline justify-between gap-2 tabular-nums">
                            <strong class="text-lg font-semibold text-stone-900">{{ row.summaryText }}</strong>
                            <span class="text-xs text-stone-500">{{ row.shareText }}</span>
                        </span>
                    </li>
                </ul>
                <p class="text-xs text-stone-500">{{ summaryLabel }}と、店舗の合計に対する割合です。</p>
                <SalesChart :columns="columns" :series="series" :format-value="formats.formatValue" :format-axis="formats.formatAxis" :axis-unit="formats.axisUnit" :unit="unit" :height="340" />
            </div>
        </section>
    </div>
</template>
