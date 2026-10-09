<script setup lang="ts">
import { computed, ref } from "vue";
import { ApiError } from "@/api/client";
import { RouterLink } from "vue-router";
import { saveSalesTarget } from "@/api/salesTargets";
import { formatLargeYen, formatQuantity, formatYen, monthLabel, toQuantity } from "@/components/salesTarget/format";
import type { ItemTargetPlan } from "@/types/api";

/**
 * 品番の年間販売目標の登録・変更（バックログ B-110、決定記録 K-049①・K-107）。
 * 月は空欄なら「年間×月別販売比率」、SKUは空欄なら「年間を直近12か月の販売数の構成比で割り振った数」を使う。
 * 入力した値は手で直した値として保存し、保存するたびに新しい版として記録する。
 * 売上金額の概算は、単価の画面で登録した全体の単価（無ければ直近12か月の平均単価）で見積もる（K-062・K-063）。単価はここでは変えない。
 */

const props = defineProps<{ plan: ItemTargetPlan; fiscalYear: number; months: string[]; ratios: number[] | null }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();

const target = props.plan.target;
const annual = ref<number | string>(target?.annual_quantity ?? "");
const note = ref("");
const monthly = ref<(number | string)[]>((target?.monthly_quantities ?? Array(12).fill(null)).map((value) => value ?? ""));
const skuOverrides = ref<Record<number, number | string>>(Object.fromEntries(Object.entries(target?.sku_quantities ?? {}).map(([skuId, quantity]) => [Number(skuId), quantity])));
const isSaving = ref(false);
const errors = ref<Record<string, string[]>>({});

const annualNumber = computed(() => toQuantity(annual.value) ?? 0);

/** 月を直していないときの値（年間×月別販売比率）。比率が無ければ null。 */
const defaultMonthly = computed(() => props.months.map((_, index) => (props.ratios ? (annualNumber.value * props.ratios[index]!) / 10000 : null)));
const itemMonthly = computed(() => defaultMonthly.value.map((value, index) => toQuantity(monthly.value[index]) ?? value));
const monthlyTotal = computed(() => itemMonthly.value.reduce<number>((total, value) => total + (value ?? 0), 0));
const hasMonthOverride = computed(() => monthly.value.some((value) => toQuantity(value) !== null));

const totalSales = computed(() => props.plan.skus.reduce((total, sku) => total + sku.sales_quantity, 0));
const skuRows = computed(() =>
    props.plan.skus.map((sku) => {
        const defaultAnnual = totalSales.value > 0 ? (annualNumber.value * sku.sales_quantity) / totalSales.value : null;
        const override = toQuantity(skuOverrides.value[sku.sku_id]);
        return { sku, defaultAnnual, override, annual: override ?? defaultAnnual };
    }),
);
const skuTotal = computed(() => (skuRows.value.every((row) => row.annual !== null) ? skuRows.value.reduce((total, row) => total + row.annual!, 0) : null));
const hasSkuOverride = computed(() => skuRows.value.some((row) => row.override !== null));

/** 売上金額の概算（SKUの合計×単価）。品番全体の単価で見積もる。 */
const priceForEstimate = computed(() => props.plan.unit_price ?? props.plan.actual_unit_price);
const estimatedAmount = computed(() => (priceForEstimate.value === null ? null : (skuTotal.value ?? annualNumber.value) * priceForEstimate.value));

function clearMonths(): void {
    monthly.value = Array(12).fill("");
}

function clearSkus(): void {
    skuOverrides.value = {};
}

function errorOf(prefix: string): string | null {
    const key = Object.keys(errors.value).find((name) => name === prefix || name.startsWith(`${prefix}.`));
    return key ? (errors.value[key]?.[0] ?? null) : null;
}

async function save(): Promise<void> {
    if (isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = {};
    try {
        await saveSalesTarget({
            fiscal_year: props.fiscalYear,
            item_no: props.plan.item_no,
            annual_quantity: annualNumber.value,
            monthly_quantities: monthly.value.map((value) => toQuantity(value)),
            sku_quantities: Object.fromEntries(props.plan.skus.map((sku) => [sku.sku_id, toQuantity(skuOverrides.value[sku.sku_id])])),
            note: note.value.trim() || null,
        });
        emit("saved");
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = error.errors;
        }
    } finally {
        isSaving.value = false;
    }
}

const inputClass = "h-10 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
const smallInputClass = "h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-right text-[13px] tabular-nums placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
</script>

<template>
    <form class="flex flex-col gap-6" novalidate @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-[12rem_12rem_minmax(0,1fr)]">
            <label class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-stone-700">年間販売目標（品番全体）</span>
                <input v-model="annual" type="number" min="1" inputmode="numeric" :class="inputClass" class="text-right text-base font-semibold tabular-nums" data-testid="annual" />
                <span v-if="errorOf('annual_quantity')" class="text-xs text-red-700">{{ errorOf("annual_quantity") }}</span>
            </label>
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-stone-700">売上金額の概算<span class="ml-1 font-normal text-stone-500">（税込）</span></span>
                <span class="flex h-10 items-center justify-end rounded-lg bg-stone-50 px-3 text-base font-semibold text-stone-900 tabular-nums" data-testid="estimated-amount">{{
                    estimatedAmount === null ? "—" : formatLargeYen(estimatedAmount)
                }}</span>
                <span class="text-[11px] text-stone-500" data-testid="price-note">
                    <template v-if="plan.unit_price !== null">登録単価 {{ formatYen(plan.unit_price) }}</template>
                    <template v-else-if="plan.actual_unit_price !== null">平均 {{ formatYen(plan.actual_unit_price) }}（単価は未登録）</template>
                    <template v-else>単価なし</template>
                    ・<RouterLink to="/unit-prices" class="underline underline-offset-2 hover:text-stone-900">単価の画面で変える</RouterLink>
                </span>
            </div>
            <label class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-stone-700">メモ<span class="ml-1 font-normal text-stone-500">（変更の理由など、任意）</span></span>
                <input v-model="note" type="text" maxlength="500" placeholder="例：新色の追加で上方修正" :class="inputClass" />
            </label>
        </div>

        <section class="flex flex-col gap-2.5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h3 class="text-xs font-medium text-stone-700">
                    月ごとの目標
                    <span class="ml-1 font-normal text-stone-500">{{
                        ratios ? `空欄の月は、年間×${plan.ratio_category_name}の月別販売比率を使います` : "この品番の区分の月別販売比率が無いため、月に分けられません"
                    }}</span>
                </h3>
                <div class="flex items-center gap-2 text-xs tabular-nums">
                    <span :class="Math.round(monthlyTotal) !== annualNumber ? 'font-semibold text-amber-700' : 'text-stone-500'" data-testid="monthly-total">
                        12か月の合計 {{ formatQuantity(monthlyTotal)
                        }}<template v-if="Math.round(monthlyTotal) !== annualNumber">（年間と {{ formatQuantity(monthlyTotal - annualNumber) }} 違います）</template>
                    </span>
                    <button v-if="hasMonthOverride" type="button" class="h-7 rounded-md px-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900" @click="clearMonths">手直しを消す</button>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                <label v-for="(month, index) in months" :key="month" class="flex flex-col gap-1">
                    <span class="text-[11px] text-stone-500 tabular-nums">{{ monthLabel(month) }}</span>
                    <input
                        v-model="monthly[index]"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        :placeholder="defaultMonthly[index] === null ? '—' : formatQuantity(defaultMonthly[index]!)"
                        :class="[smallInputClass, toQuantity(monthly[index]) !== null ? 'border-stone-900 font-semibold' : '']"
                        :aria-label="`${monthLabel(month)}の目標`"
                    />
                </label>
            </div>
            <span v-if="errorOf('monthly_quantities')" class="text-xs text-red-700">{{ errorOf("monthly_quantities") }}</span>
        </section>

        <section class="flex flex-col gap-2.5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h3 class="text-xs font-medium text-stone-700">
                    SKUごとの年間の数
                    <span class="ml-1 font-normal text-stone-500">空欄のSKUは、年間を直近12か月の販売数の構成比で割り振ります</span>
                </h3>
                <div class="flex items-center gap-2 text-xs tabular-nums">
                    <span v-if="skuTotal !== null" :class="Math.round(skuTotal) !== annualNumber ? 'font-semibold text-stone-900' : 'text-stone-500'" data-testid="sku-total">
                        SKUの合計 {{ formatQuantity(skuTotal)
                        }}<template v-if="Math.round(skuTotal) !== annualNumber"
                            >（年間より {{ formatQuantity(Math.abs(skuTotal - annualNumber)) }} {{ skuTotal > annualNumber ? "多い" : "少ない" }}。SKUの数を正とします）</template
                        >
                    </span>
                    <button v-if="hasSkuOverride" type="button" class="h-7 rounded-md px-2 text-stone-600 hover:bg-stone-100 hover:text-stone-900" @click="clearSkus">上書きを消す</button>
                </div>
            </div>
            <div class="max-h-80 overflow-auto rounded-lg border border-stone-200">
                <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:sticky [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-3 py-2">SKUコード</th>
                            <th scope="col" class="px-3 py-2 text-right whitespace-nowrap">直近12か月の販売数</th>
                            <th scope="col" class="px-3 py-2 text-right whitespace-nowrap">構成比で割り振った数</th>
                            <th scope="col" class="w-32 px-3 py-2 text-right">上書き</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in skuRows" :key="row.sku.sku_id" :class="index > 0 ? '[&>*]:border-t [&>*]:border-stone-100' : ''">
                            <td class="px-3 py-1.5 font-mono text-[12.5px] text-stone-800">{{ row.sku.sku_code }}</td>
                            <td class="px-3 py-1.5 text-right text-stone-600 tabular-nums">{{ formatQuantity(row.sku.sales_quantity) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums" :class="row.override !== null ? 'text-stone-400 line-through' : 'text-stone-700'">
                                {{ row.defaultAnnual === null ? "—" : formatQuantity(row.defaultAnnual) }}
                            </td>
                            <td class="px-3 py-1.5">
                                <input
                                    v-model="skuOverrides[row.sku.sku_id]"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    placeholder="—"
                                    :class="[smallInputClass, row.override !== null ? 'border-stone-900 font-semibold' : '']"
                                    :aria-label="`${row.sku.sku_code} の年間の数`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="totalSales === 0" class="text-xs text-amber-700">直近12か月の販売実績が無いため、構成比で割り振れません。SKUごとの数を入れてください。</p>
            <span v-if="errorOf('sku_quantities')" class="text-xs text-red-700">{{ errorOf("sku_quantities") }}</span>
        </section>

        <div class="flex justify-end gap-2 border-t border-stone-200 pt-4">
            <button type="button" class="h-10 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">キャンセル</button>
            <button
                type="submit"
                class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving || annualNumber < 1"
            >
                {{ isSaving ? "保存中…" : "保存" }}
            </button>
        </div>
    </form>
</template>
