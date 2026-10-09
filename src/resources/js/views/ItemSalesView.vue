<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from "vue-router";
import ActualsTable from "@/components/actuals/ActualsTable.vue";
import ItemSalesChart from "@/components/actuals/ItemSalesChart.vue";
import SalesActualsToolbar from "@/components/actuals/SalesActualsToolbar.vue";
import SalesScopeControls from "@/components/actuals/SalesScopeControls.vue";
import type { ActualItemRows } from "@/components/actuals/actualRows";
import { useSalesActuals } from "@/components/actuals/useSalesActuals";

/**
 * 品番別売上（バックログ B-124・B-127）。左メニュー「販売実績」の画面。対象品番ごとの販売数・金額の合計を、月ごと（直近12か月＋今月）か
 * 日ごと（直近30日）に表・グラフで見る。SKUごとの実績は出さず、品番を押すとその品番のSKU別売上の画面へ移る（K-078）。
 * 全体・Amazon・BOSSの切り替え、見せ方と色は在庫推移の画面（K-047・K-074）とそろえる。販売実績を取り込んでいない月・日は0とせず「—」で示す（原則2）。
 * グラフは販売実績用に作った、品番の一覧と選んだ品番のモール別の積み上げ棒グラフ（K-079）。選んだ品番は URL（item）に持たせる。
 * 期間は引き出しか開始〜終了で指定できる（K-080）。選んだモール・月ごとと日ごと・数量と金額・見せ方・期間は URL に持たせ、
 * SKU別売上の画面へもそのまま引き継ぐ。
 */

const route = useRoute();
const router = useRouter();
const { scope, unit, measure, view, updateQuery, result, isLoading, hasError, periodError, load, columns, itemRows, summaryLabel, notSoldLabel, rangeNote } = useSalesActuals(ref(null));

const keyword = ref("");

const visibleItems = computed<ActualItemRows[]>(() => {
    const word = keyword.value.trim().toLowerCase();
    return word === "" ? itemRows.value : itemRows.value.filter((group) => group.item.item_no.toLowerCase().includes(word) || group.item.brand.toLowerCase().includes(word));
});

/** 品番のSKU別売上の画面（選んでいるモールなどを引き継ぐ）。 */
const skuSalesLink = (itemNo: string): RouteLocationRaw => ({ name: "sku-sales", params: { itemNo }, query: { ...route.query, item: undefined } });

/** グラフで選んでいる品番。 */
const selectedItemNo = computed(() => (typeof route.query.item === "string" ? route.query.item : null));

function selectItem(itemNo: string): void {
    void router.replace({ query: { ...route.query, item: itemNo } });
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">販売実績 / 品番別売上</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">品番別売上</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="description">
                    取り込んだ販売実績の、品番ごとの{{ unit === "day" ? "日ごと" : "月ごと" }}の販売数です。期間を選べます。品番を押すと、SKUごとの売上を見られます。
                </p>
            </div>
            <SalesScopeControls :scope="scope" :is-loading="isLoading" @select="updateQuery({ scope: $event })" @reload="load" />
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
            <SalesActualsToolbar
                v-model:keyword="keyword"
                :unit="unit"
                :measure="measure"
                :view="view"
                :range-note="rangeNote"
                search-label="品番・ブランドで絞り込み"
                :meta="result.meta"
                :period-error="periodError"
                @update="updateQuery"
            />
            <div v-if="visibleItems.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">
                {{ keyword.trim() === "" ? "対象品番がありません。" : "条件に一致する品番はありません。" }}
            </div>
            <ActualsTable
                v-else-if="view === 'table'"
                :items="visibleItems"
                :columns="columns"
                :summary-label="summaryLabel"
                :not-sold-label="notSoldLabel"
                :column-width="unit === 'day' ? 64 : 72"
                :item-link="skuSalesLink"
            />
            <ItemSalesChart
                v-else
                :items="visibleItems"
                :columns="columns"
                :measure="measure"
                :unit="unit"
                :summary-label="summaryLabel"
                :selected-item-no="selectedItemNo"
                :sku-sales-link="skuSalesLink"
                @select="selectItem"
            />
        </section>
    </div>
</template>
