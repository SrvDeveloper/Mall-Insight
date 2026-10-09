<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import ColorSizeMatrixDialog from "@/components/actuals/ColorSizeMatrixDialog.vue";
import MallShareBar from "@/components/actuals/MallShareBar.vue";
import SalesActualsToolbar from "@/components/actuals/SalesActualsToolbar.vue";
import SalesScopeControls from "@/components/actuals/SalesScopeControls.vue";
import SkuSalesDetail from "@/components/actuals/SkuSalesDetail.vue";
import SkuSalesList from "@/components/actuals/SkuSalesList.vue";
import { buildSkuSales, formatShare, formatTotal } from "@/components/actuals/skuSales";
import { useSalesActuals } from "@/components/actuals/useSalesActuals";

/**
 * SKU別売上（バックログ B-127、K-081）。品番別売上の画面で品番を押すと移る画面。画面案 E-2 の形で、上に品番の合計（期間計・モールの割合・
 * 一番売れたSKU・一番売れた月）、左にSKUの一覧（2色の棒＝多さとモールの割合）、右に選んだSKUの BOSS・Amazon の折れ線と月（日）ごとの表を出す。
 * カラー×度数の表はモーダルで開き、度数の無い品番ではボタンを押せない。期間は引き出しか開始〜終了で指定できる（K-080）。
 * 月ごとと日ごと、数量と金額、全体・Amazon・BOSS、期間、選んだSKU（sku）は URL に持たせ、品番別売上へ戻るときもSKU以外は引き継ぐ。
 * 対象品番でない品番は出さない（K-075）。
 */

const route = useRoute();
const router = useRouter();
const itemNo = computed(() => String(route.params.itemNo ?? ""));
const { scope, unit, measure, updateQuery, result, isLoading, hasError, periodError, load, columns, notSoldLabel, rangeNote } = useSalesActuals(itemNo);

const item = computed(() => result.value?.data[0] ?? null);
const model = computed(() => (item.value ? buildSkuSales(item.value, measure.value) : null));

/** 選んでいるSKU（URL の sku。無ければ期間計の一番多いSKU）。 */
const selectedRow = computed(() => {
    const rows = model.value?.rows ?? [];
    const skuId = Number(route.query.sku);
    return rows.find((row) => row.sku.sku_id === skuId) ?? rows[0] ?? null;
});

function selectSku(skuId: number): void {
    void router.replace({ query: { ...route.query, sku: String(skuId) } });
}

const isMatrixOpen = ref(false);

function selectFromMatrix(skuId: number): void {
    isMatrixOpen.value = false;
    selectSku(skuId);
}

/** 品番別売上へ戻る（選んでいるモールなどを引き継ぎ、SKUは持っていかない）。 */
const itemSalesLink = computed(() => ({ name: "item-sales", query: { ...route.query, sku: undefined } }));

const top = computed(() => Math.max(0, ...(model.value?.rows ?? []).map((row) => row.sum)));
const mallShare = (mall: "boss" | "amazon"): number => (model.value && model.value.sum > 0 ? model.value.mallSums[mall] / model.value.sum : 0);
const bestColumn = computed(() => (model.value?.best ? columns.value[model.value.best.index] : null));
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">
                    販売実績 /
                    <RouterLink :to="itemSalesLink" class="underline-offset-4 hover:text-stone-900 hover:underline" data-testid="back-to-items">品番別売上</RouterLink>
                    / SKU別売上
                </p>
                <h1 class="flex flex-wrap items-baseline gap-x-3 text-[26px] leading-tight font-bold tracking-tight text-stone-900">
                    SKU別売上
                    <span class="font-mono text-xl font-medium" data-testid="item-no">{{ itemNo }}</span>
                    <span v-if="item" class="self-center rounded-full border border-stone-200 bg-white px-2 py-0.5 text-xs font-normal tracking-normal text-stone-600"
                        >{{ item.brand }}・{{ item.category }}</span
                    >
                </h1>
            </div>
            <SalesScopeControls :scope="scope" :is-loading="isLoading" @select="updateQuery({ scope: $event })" @reload="load" />
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">販売実績を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="!item || !model" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-6 text-center text-sm text-amber-900" data-testid="not-target">
            {{ itemNo }} は対象品番にありません。<RouterLink :to="itemSalesLink" class="font-semibold underline">品番別売上</RouterLink>から品番を選んでください。
        </div>

        <template v-else>
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <SalesActualsToolbar :unit="unit" :measure="measure" :range-note="rangeNote" :meta="result.meta" :period-error="periodError" @update="updateQuery" />
                <!-- 品番の合計 -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" aria-label="品番の合計" data-testid="item-summary">
                    <div class="flex flex-col gap-1 px-5 py-4">
                        <span class="text-[11px] text-stone-500">品番の期間計</span>
                        <span class="text-[22px] font-bold text-stone-900 tabular-nums" data-testid="item-sum">{{ formatTotal(model.sum, measure) }}</span>
                        <span class="text-xs text-stone-400">{{ model.rows.length }} SKU</span>
                    </div>
                    <div class="flex flex-col gap-1.5 border-stone-200 px-5 py-4 max-sm:border-t sm:border-l">
                        <span class="text-[11px] text-stone-500">モールの割合</span>
                        <template v-if="model.malls.length >= 2">
                            <MallShareBar :boss="mallShare('boss')" :amazon="mallShare('amazon')" :height="10" />
                            <span class="flex justify-between gap-2 text-xs text-stone-600 tabular-nums" data-testid="item-malls"
                                ><span>BOSS {{ formatTotal(model.mallSums.boss, measure) }}（{{ formatShare(mallShare("boss")) }}）</span
                                ><span>Amazon {{ formatTotal(model.mallSums.amazon, measure) }}（{{ formatShare(mallShare("amazon")) }}）</span></span
                            >
                        </template>
                        <span v-else class="text-sm text-stone-600">{{ result.meta.scope_label }}だけを表示しています</span>
                    </div>
                    <div class="flex flex-col gap-1 border-stone-200 px-5 py-4 max-lg:border-t lg:border-l">
                        <span class="text-[11px] text-stone-500">一番売れたSKU</span>
                        <template v-if="model.rows[0] && model.rows[0].sum > 0">
                            <span class="truncate font-mono text-lg font-medium text-stone-900" data-testid="top-sku">{{ model.rows[0].sku.sku_code }}</span>
                            <span class="text-xs text-stone-400 tabular-nums">{{ formatTotal(model.rows[0].sum, measure) }}（{{ formatShare(model.rows[0].share) }}）</span>
                        </template>
                        <span v-else class="text-sm text-stone-400">—</span>
                    </div>
                    <div class="flex flex-col gap-1 border-stone-200 px-5 py-4 max-lg:border-t sm:border-l">
                        <span class="text-[11px] text-stone-500">一番売れた{{ unit === "day" ? "日" : "月" }}</span>
                        <template v-if="bestColumn && model.best">
                            <span class="text-[22px] font-bold text-stone-900 tabular-nums" data-testid="best-period">{{ bestColumn.label }}</span>
                            <span class="text-xs text-stone-400 tabular-nums">{{ formatTotal(model.best.value, measure) }}</span>
                        </template>
                        <span v-else class="text-sm text-stone-400">—</span>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-start gap-5">
                <div class="min-w-0 flex-[1_1_26rem] lg:max-w-[30rem]">
                    <SkuSalesList
                        :rows="model.rows"
                        :selected-sku-id="selectedRow?.sku.sku_id ?? null"
                        :measure="measure"
                        :has-matrix="model.matrix !== null"
                        :not-sold-label="notSoldLabel"
                        @select="selectSku"
                        @open-matrix="isMatrixOpen = true"
                    />
                </div>
                <div class="min-w-0 flex-[999_1_34rem]">
                    <SkuSalesDetail v-if="selectedRow" :row="selectedRow" :columns="columns" :measure="measure" :unit="unit" :malls="model.malls" :not-sold-label="notSoldLabel" />
                </div>
            </div>

            <ColorSizeMatrixDialog
                v-if="model.matrix"
                :open="isMatrixOpen"
                :item-no="itemNo"
                :matrix="model.matrix"
                :total="model.sum"
                :top="top"
                :measure="measure"
                :selected-sku-id="selectedRow?.sku.sku_id ?? null"
                @close="isMatrixOpen = false"
                @select="selectFromMatrix"
            />
        </template>
    </div>
</template>
