<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { fetchSalesTargets, saveItemRatioCategory } from "@/api/salesTargets";
import MonthlySalesRatioEditor from "@/components/salesTarget/MonthlySalesRatioEditor.vue";
import SalesTargetCarryOver from "@/components/salesTarget/SalesTargetCarryOver.vue";
import SalesTargetForm from "@/components/salesTarget/SalesTargetForm.vue";
import SalesTargetHistory from "@/components/salesTarget/SalesTargetHistory.vue";
import { formatLargeYen, formatQuantity, formatThousandYen, formatYen, monthLabel } from "@/components/salesTarget/format";
import type { ItemTargetPlan, SalesTargetResponse } from "@/types/api";

/**
 * 販売目標（バックログ B-110、決定記録 K-048・K-049・K-106～K-108）。年度ごとに品番の年間販売目標を登録し、
 * 月別販売比率で月に分け、SKUへ割り振る。割り振りは保存せず、開くたびに最新の目標・比率・販売実績から計算する。
 * 年間販売目標はシステム需要予測の計算に使わない（原則1）。在庫推移の「販売試算」（B-122）の元になる。
 *
 * 見た目は在庫推移の画面（K-047）とそろえる。年度と見せる内容（年間販売目標・月別販売比率）は URL に持たせる。
 */

type Tab = "targets" | "ratios";
/** 一覧に出す値。数量か、売上金額の概算（税込、K-062）か */
type Measure = "quantity" | "amount";

const TABS: { value: Tab; label: string }[] = [
    { value: "targets", label: "年間販売目標" },
    { value: "ratios", label: "月別販売比率" },
];

const route = useRoute();
const router = useRouter();

const result = shallowRef<SalesTargetResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const expanded = ref(new Set<string>());
/** 開いているダイアログ。目標の登録・変更と履歴は品番ごと、引き継ぎは年度全体。 */
const editing = ref<{ kind: "form" | "history"; plan: ItemTargetPlan } | { kind: "carry-over" } | null>(null);
const message = ref<string | null>(null);

const tab = computed<Tab>(() => (route.query.tab === "ratios" ? "ratios" : "targets"));
const measure = computed<Measure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));
const requestedYear = computed(() => {
    const year = Number(route.query.fy);
    return Number.isInteger(year) && year >= 2000 && year <= 2100 ? year : undefined;
});

function updateQuery(changes: { tab?: Tab; fy?: number; measure?: Measure }): void {
    const nextTab = changes.tab ?? tab.value;
    const nextYear = changes.fy ?? result.value?.meta.fiscal_year;
    const nextMeasure = changes.measure ?? measure.value;
    const current = result.value?.meta.current_fiscal_year;
    void router.replace({
        query: {
            ...(nextTab === "ratios" ? { tab: nextTab } : {}),
            ...(nextYear !== undefined && nextYear !== current ? { fy: String(nextYear) } : {}),
            ...(nextMeasure === "amount" ? { measure: nextMeasure } : {}),
        },
    });
}

/** 品番・SKUの単価の説明（「登録単価 4,400円」「平均 3,950円」）。 */
function unitPriceText(plan: ItemTargetPlan): string | null {
    if (plan.unit_price !== null) {
        return `登録単価 ${formatYen(plan.unit_price)}`;
    }
    return plan.actual_unit_price === null ? null : `平均 ${formatYen(plan.actual_unit_price)}`;
}

let latestRequest = 0;
async function load(): Promise<void> {
    const requestId = ++latestRequest;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchSalesTargets(requestedYear.value);
        if (requestId === latestRequest) {
            result.value = response;
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

watch(requestedYear, load, { immediate: true });

const months = computed(() => result.value?.meta.months ?? []);
const fiscalYear = computed(() => result.value?.meta.fiscal_year ?? 0);
const ratioOf = (categoryId: number | null): number[] | null => result.value?.meta.ratio_categories.find((entry) => entry.id === categoryId)?.ratio?.ratios ?? null;

/** 月の見出しの上に出す年の段（4月～12月は今年度の年、1月～3月は翌年）。 */
const yearSpans = computed(() => {
    const spans: { year: string; span: number }[] = [];
    for (const month of months.value) {
        const year = month.slice(0, 4);
        const last = spans.at(-1);
        if (last && last.year === year) {
            last.span++;
        } else {
            spans.push({ year, span: 1 });
        }
    }
    return spans;
});

const items = computed(() => result.value?.data ?? []);

/** まとめの帯。 */
const facts = computed(() => {
    const list = items.value;
    const registered = list.filter((plan) => plan.target !== null);
    const targetItems = list.filter((plan) => plan.is_target_item);
    const total = registered.reduce((sum, plan) => sum + (plan.sku_total ?? plan.target!.annual_quantity), 0);
    const amount = registered.reduce((sum, plan) => sum + (plan.annual_amount ?? 0), 0);
    const unpriced = registered.filter((plan) => plan.has_unpriced_sku).length;
    const uncategorized = list.filter((plan) => plan.ratio_category_id === null).length;
    const notSplit = registered.filter((plan) => plan.status !== "calculated").length;
    return [
        {
            key: "registered",
            label: "年間販売目標",
            value: `${registered.length} / ${list.length}品番`,
            note: `対象品番 ${targetItems.length}品番のうち ${targetItems.filter((plan) => plan.target !== null).length}品番に登録`,
            warn: targetItems.some((plan) => plan.target === null),
        },
        {
            key: "total",
            label: "目標の合計（SKUの合計）",
            value: `${formatQuantity(total)}個`,
            note: `売上金額の概算 ${formatLargeYen(amount)}（税込）${unpriced > 0 ? `・単価なし ${unpriced}品番を除く` : ""}`,
            warn: unpriced > 0,
        },
        {
            key: "category",
            label: "比率の区分",
            value: uncategorized === 0 ? "すべて設定済み" : `未設定 ${uncategorized}品番`,
            note: uncategorized === 0 ? "品番ごとに変えられます" : "区分を選ぶまで月に分けられません",
            warn: uncategorized > 0,
        },
        {
            key: "not-split",
            label: "割り振れない品番",
            value: `${notSplit}品番`,
            note: notSplit === 0 ? "すべて月・SKUに割り振れています" : "理由は一覧に出ています",
            warn: notSplit > 0,
        },
    ];
});

function toggle(itemNo: string): void {
    const next = new Set(expanded.value);
    if (next.has(itemNo)) {
        next.delete(itemNo);
    } else {
        next.add(itemNo);
    }
    expanded.value = next;
}

async function changeCategory(plan: ItemTargetPlan, categoryId: number): Promise<void> {
    try {
        await saveItemRatioCategory(plan.item_no, categoryId);
        message.value = `${plan.item_no} の比率の区分を変更しました。`;
        await load();
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeDialog();
    }
}

async function openDialog(kind: "form" | "history" | "carry-over", plan?: ItemTargetPlan): Promise<void> {
    editing.value = kind === "carry-over" ? { kind } : { kind, plan: plan! };
    message.value = null;
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    closeButton.value?.focus();
}

function closeDialog(): void {
    editing.value = null;
    document.removeEventListener("keydown", onKeydown);
}

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

async function onSaved(): Promise<void> {
    const itemNo = editing.value && "plan" in editing.value ? editing.value.plan.item_no : "";
    closeDialog();
    message.value = `${itemNo} の${fiscalYear.value}年度の販売目標を保存しました。`;
    await load();
}

async function onCarriedOver(count: number): Promise<void> {
    closeDialog();
    message.value = `${fiscalYear.value - 1}年度から${count}品番の目標を引き継ぎました。`;
    await load();
}

async function onRatioSaved(text: string): Promise<void> {
    message.value = text;
    await load();
}

const categoryOptions = computed(() => result.value?.meta.ratio_categories ?? []);
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 販売目標</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">販売目標</h1>
                <p class="max-w-3xl text-[13px] text-stone-600">
                    品番ごとの年間販売目標（現行Excelの年間販売想定数）を登録し、月別販売比率で月に分けてSKUへ割り振ります。需要予測には使いません。在庫推移の「販売試算」に使います。
                </p>
            </div>
            <div v-if="result" class="inline-flex items-center gap-1 rounded-lg border border-stone-300 bg-white p-1" role="group" aria-label="年度">
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-md text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="前の年度"
                    data-testid="previous-year"
                    @click="updateQuery({ fy: fiscalYear - 1 })"
                >
                    <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m10 3-5 5 5 5" />
                    </svg>
                </button>
                <span class="flex min-w-36 flex-col items-center px-2 leading-tight" data-testid="fiscal-year">
                    <span class="text-sm font-semibold text-stone-900 tabular-nums">{{ fiscalYear }}年度</span>
                    <span class="text-[10px] text-stone-500 tabular-nums">{{ fiscalYear }}/4～{{ fiscalYear + 1 }}/3{{ fiscalYear === result.meta.current_fiscal_year ? "・今年度" : "" }}</span>
                </span>
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-md text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="次の年度"
                    data-testid="next-year"
                    @click="updateQuery({ fy: fiscalYear + 1 })"
                >
                    <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m6 3 5 5-5 5" />
                    </svg>
                </button>
            </div>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">販売目標を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <template v-else>
            <section class="grid grid-cols-2 rounded-xl border border-stone-200 bg-white lg:grid-cols-4" aria-label="販売目標のまとめ" data-testid="target-summary">
                <div
                    v-for="(fact, index) in facts"
                    :key="fact.key"
                    class="flex flex-col gap-1 px-5 py-4"
                    :class="index > 0 ? 'border-stone-200 max-lg:odd:border-t max-lg:even:border-l lg:border-l' : ''"
                    :data-testid="`summary-${fact.key}`"
                >
                    <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                    <span class="text-base font-semibold tabular-nums" :class="fact.warn ? 'text-amber-800' : 'text-stone-900'">{{ fact.value }}</span>
                    <span class="text-xs" :class="fact.warn ? 'font-medium text-amber-700' : 'text-stone-400'">{{ fact.note }}</span>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="見せる内容">
                        <button
                            v-for="option in TABS"
                            :key="option.value"
                            type="button"
                            role="tab"
                            class="inline-flex h-8 items-center rounded-[7px] px-3.5 text-[13px]"
                            :class="tab === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                            :aria-selected="tab === option.value"
                            :data-testid="`tab-${option.value}`"
                            @click="updateQuery({ tab: option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div v-if="tab === 'targets'" class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="一覧に出す値">
                            <button
                                v-for="option in [
                                    { value: 'quantity' as Measure, label: '数量' },
                                    { value: 'amount' as Measure, label: '金額' },
                                ]"
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
                        <p class="text-xs text-stone-500 tabular-nums">
                            <template v-if="tab === 'targets' && measure === 'amount'">金額は税込の概算（千円）。単価は品番に登録した単価、無ければ直近12か月の平均単価です</template>
                            <template v-else-if="tab === 'targets'"
                                >SKUへの構成比は、{{ result.meta.composition_from.replaceAll("-", "/") }}～{{
                                    result.meta.composition_to.replaceAll("-", "/")
                                }}
                                の販売数（全モール）で出します</template
                            >
                            <template v-else>12か月の合計が100.00%のときだけ保存できます</template>
                        </p>
                        <button
                            v-if="tab === 'targets'"
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 text-xs font-medium text-stone-900 hover:bg-stone-100"
                            data-testid="open-carry-over"
                            @click="openDialog('carry-over')"
                        >
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2.5 8h9M8.5 4.5 12 8l-3.5 3.5" />
                                <path d="M14 3v10" />
                            </svg>
                            {{ fiscalYear - 1 }}年度から引き継ぐ
                        </button>
                    </div>
                </div>

                <MonthlySalesRatioEditor v-if="tab === 'ratios'" :categories="result.meta.ratio_categories" :months="months" @saved="onRatioSaved" />

                <template v-else>
                    <div v-if="items.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">対象品番がまだ確定されていません。対象品番の画面で確定すると、ここに表示されます。</div>
                    <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                        <table class="w-full min-w-[84rem] table-fixed border-separate border-spacing-0 text-left text-[13px]" data-testid="target-table">
                            <colgroup>
                                <col class="w-60" />
                                <col class="w-36" />
                                <col class="w-28" />
                                <col v-for="month in months" :key="month" class="w-16" />
                                <col class="w-32" />
                            </colgroup>
                            <thead class="[&_th]:bg-white">
                                <tr class="text-[11px] font-semibold tracking-wider text-stone-400">
                                    <th colspan="3"></th>
                                    <th
                                        v-for="(span, index) in yearSpans"
                                        :key="span.year"
                                        :colspan="span.span"
                                        class="px-3 pt-2 pb-0.5 text-left"
                                        :class="index > 0 ? 'shadow-[inset_1px_0_0_var(--color-stone-200)]' : ''"
                                    >
                                        {{ span.year }}
                                    </th>
                                    <th></th>
                                </tr>
                                <tr class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300">
                                    <th scope="col" class="px-4 pt-1 pb-2">品番</th>
                                    <th scope="col" class="px-3 pt-1 pb-2">比率の区分</th>
                                    <th scope="col" class="px-3 pt-1 pb-2 text-right whitespace-nowrap">{{ measure === "amount" ? "年間（千円）" : "年間" }}</th>
                                    <th v-for="month in months" :key="month" scope="col" class="px-2 pt-1 pb-2 text-right tabular-nums">{{ monthLabel(month) }}</th>
                                    <th scope="col" class="px-4 pt-1 pb-2"><span class="sr-only">操作</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="plan in items" :key="plan.item_no">
                                    <tr class="h-12 bg-stone-50 [&>*]:border-t [&>*]:border-stone-200" data-testid="target-row">
                                        <th scope="rowgroup" class="px-4 text-left font-normal">
                                            <div class="flex items-center gap-1.5 overflow-hidden whitespace-nowrap">
                                                <button
                                                    v-if="plan.target && plan.skus.length > 0"
                                                    type="button"
                                                    class="-ml-1.5 flex size-6 shrink-0 items-center justify-center rounded-md text-stone-500 hover:bg-stone-200 hover:text-stone-900"
                                                    :aria-expanded="expanded.has(plan.item_no)"
                                                    :aria-label="`${plan.item_no} のSKUを${expanded.has(plan.item_no) ? '閉じる' : '表示'}`"
                                                    @click="toggle(plan.item_no)"
                                                >
                                                    <svg
                                                        class="size-3.5 transition-transform"
                                                        :class="expanded.has(plan.item_no) ? 'rotate-90' : ''"
                                                        viewBox="0 0 20 20"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        aria-hidden="true"
                                                    >
                                                        <path d="m8 5 5 5-5 5" />
                                                    </svg>
                                                </button>
                                                <span v-else class="-ml-1.5 size-6 shrink-0" aria-hidden="true" />
                                                <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ plan.item_no }}</span>
                                                <span v-if="plan.exists_in_crosswalker" class="min-w-0 truncate rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600"
                                                    >{{ plan.brand }}・{{ plan.category }}</span
                                                >
                                                <span v-else class="text-[11px] text-amber-700">CrossWalkerから消えた品番</span>
                                                <span
                                                    v-if="!plan.is_target_item"
                                                    class="shrink-0 rounded-full bg-stone-100 px-2 py-px text-[11px] text-stone-500"
                                                    title="対象品番ではありませんが、この年度の目標があります"
                                                    >対象外</span
                                                >
                                            </div>
                                        </th>
                                        <td class="px-3">
                                            <select
                                                class="h-8 w-full rounded-md border bg-white px-1.5 text-xs text-stone-900 focus:border-stone-900 focus:outline-none"
                                                :class="plan.ratio_category_id === null ? 'border-amber-400' : 'border-stone-300'"
                                                :value="plan.ratio_category_id ?? ''"
                                                :disabled="!plan.exists_in_crosswalker"
                                                :title="plan.is_ratio_category_set || plan.ratio_category_id === null ? undefined : 'CrossWalkerのカテゴリーから決めた初期値です'"
                                                :aria-label="`${plan.item_no} の比率の区分`"
                                                data-testid="category-select"
                                                @change="changeCategory(plan, Number(($event.target as HTMLSelectElement).value))"
                                            >
                                                <option v-if="plan.ratio_category_id === null" value="" disabled>選んでください</option>
                                                <option v-for="option in categoryOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
                                            </select>
                                        </td>
                                        <td class="px-3 text-right whitespace-nowrap tabular-nums">
                                            <template v-if="plan.target && measure === 'amount'">
                                                <span v-if="plan.annual_amount !== null" class="block text-sm font-semibold text-stone-900" data-testid="annual-amount">{{
                                                    formatThousandYen(plan.annual_amount)
                                                }}</span>
                                                <span v-else class="block text-xs text-amber-700">単価なし</span>
                                                <span
                                                    class="block text-[10px]"
                                                    :class="plan.has_unpriced_sku ? 'text-amber-700' : 'text-stone-500'"
                                                    :title="plan.has_unpriced_sku ? '単価を出せないSKUがあり、そのSKUは金額に含めていません' : undefined"
                                                    data-testid="unit-price"
                                                    >{{ plan.has_unpriced_sku ? "一部単価なし" : unitPriceText(plan) }}</span
                                                >
                                            </template>
                                            <template v-else-if="plan.target">
                                                <span class="block text-sm font-semibold text-stone-900">{{ formatQuantity(plan.target.annual_quantity) }}</span>
                                                <span
                                                    v-if="plan.sku_total !== null && Math.round(plan.sku_total) !== plan.target.annual_quantity"
                                                    class="block text-[10px] text-stone-500"
                                                    title="SKUの数を手で上書きしているため、SKUの合計が年間販売目標と違います（SKUの数を正とします）"
                                                    data-testid="sku-total"
                                                    >SKU計 {{ formatQuantity(plan.sku_total) }}</span
                                                >
                                            </template>
                                            <span v-else class="text-stone-300">—</span>
                                        </td>
                                        <td v-if="measure === 'amount' && plan.monthly && plan.status !== 'no_target' && !plan.monthly_amounts" :colspan="months.length" class="px-3">
                                            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs text-amber-800">販売実績も登録した単価も無いため、金額を出せません</span>
                                        </td>
                                        <template v-else-if="plan.monthly && plan.status !== 'no_target'">
                                            <td
                                                v-for="(quantity, index) in measure === 'amount' ? plan.monthly_amounts! : plan.monthly"
                                                :key="index"
                                                class="px-2 text-right tabular-nums"
                                                :class="plan.month_overridden[index] ? 'font-semibold text-stone-900' : 'text-stone-700'"
                                                :title="plan.month_overridden[index] ? '手で直した月' : undefined"
                                                data-testid="month-cell"
                                            >
                                                <span v-if="plan.month_overridden[index]" class="mr-0.5 inline-block size-1 rounded-full bg-stone-900 align-middle" aria-hidden="true" />{{
                                                    measure === "amount" ? formatThousandYen(quantity) : formatQuantity(quantity)
                                                }}
                                            </td>
                                        </template>
                                        <td v-else :colspan="months.length" class="truncate px-3" :title="plan.status_label" data-testid="status">
                                            <span class="rounded-full px-2.5 py-0.5 text-xs" :class="plan.status === 'no_target' ? 'bg-stone-100 text-stone-500' : 'bg-amber-50 text-amber-800'">{{
                                                plan.status_label
                                            }}</span>
                                        </td>
                                        <td class="px-4 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    class="h-8 rounded-lg px-2.5 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-40"
                                                    :class="
                                                        plan.target ? 'text-stone-700 hover:bg-stone-200 hover:text-stone-900' : 'border border-stone-300 bg-white text-stone-900 hover:bg-stone-100'
                                                    "
                                                    :disabled="!plan.exists_in_crosswalker"
                                                    data-testid="edit-target"
                                                    @click="openDialog('form', plan)"
                                                >
                                                    {{ plan.target ? "編集" : "登録" }}
                                                </button>
                                                <button
                                                    v-if="plan.target"
                                                    type="button"
                                                    class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-200 hover:text-stone-900"
                                                    data-testid="show-history"
                                                    @click="openDialog('history', plan)"
                                                >
                                                    履歴
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <template v-if="expanded.has(plan.item_no)">
                                        <tr v-for="sku in plan.skus" :key="sku.sku_id" class="h-10 hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="sku-row">
                                            <td class="truncate py-0 pr-3 pl-12 font-mono text-[12.5px] text-stone-700" :title="sku.sku_code">{{ sku.sku_code }}</td>
                                            <td
                                                class="truncate px-3 text-[11px] text-stone-500"
                                                :title="measure === 'amount' && sku.unit_price !== null ? `${sku.price_basis_label} ${formatYen(sku.unit_price)}` : undefined"
                                            >
                                                <template v-if="measure === 'amount'">{{ sku.unit_price === null ? "単価なし" : `${formatYen(sku.unit_price)}` }}</template>
                                                <template v-else>{{ sku.override_annual !== null ? "上書き" : "構成比" }}</template>
                                            </td>
                                            <td class="px-3 text-right tabular-nums" :class="sku.override_annual !== null ? 'font-semibold text-stone-900' : 'text-stone-700'">
                                                <template v-if="measure === 'amount'">{{ sku.annual_amount === null ? "—" : formatThousandYen(sku.annual_amount) }}</template>
                                                <template v-else>{{ sku.annual === null ? "—" : formatQuantity(sku.annual) }}</template>
                                            </td>
                                            <template v-if="measure === 'amount' ? sku.monthly_amounts : sku.monthly">
                                                <td
                                                    v-for="(quantity, index) in measure === 'amount' ? sku.monthly_amounts! : sku.monthly!"
                                                    :key="index"
                                                    class="px-2 text-right text-stone-600 tabular-nums"
                                                >
                                                    {{ measure === "amount" ? formatThousandYen(quantity) : formatQuantity(quantity) }}
                                                </td>
                                            </template>
                                            <td v-else :colspan="months.length" class="px-3 text-xs text-stone-400">—</td>
                                            <td />
                                        </tr>
                                    </template>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </section>
        </template>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeDialog">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="target-dialog-title"
                class="flex max-h-full w-full flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
                :class="editing.kind === 'history' ? 'max-w-4xl' : 'max-w-3xl'"
            >
                <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 id="target-dialog-title" class="text-base font-bold text-stone-900">
                            <template v-if="editing.kind === 'carry-over'">{{ fiscalYear - 1 }}年度から{{ fiscalYear }}年度へ目標を引き継ぐ</template>
                            <template v-else
                                ><span class="font-mono">{{ editing.plan.item_no }}</span> の{{ fiscalYear }}年度の販売目標{{ editing.kind === "history" ? "の履歴" : "" }}</template
                            >
                        </h2>
                        <p class="text-xs text-stone-500">
                            {{
                                editing.kind === "carry-over"
                                    ? "引き継ぐ品番と写す内容を選んでください。写した目標は新しい版として記録し、前の値は履歴に残ります。"
                                    : editing.kind === "form"
                                      ? "保存するたびに新しい版として記録し、前の値は履歴に残ります。"
                                      : "変更のたびに記録した版です。いちばん上が今の目標です。"
                            }}
                        </p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeDialog"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto" :class="editing.kind === 'form' ? 'px-5 py-5' : ''">
                    <SalesTargetCarryOver v-if="editing.kind === 'carry-over'" :fiscal-year="fiscalYear" @saved="onCarriedOver" @cancel="closeDialog" />
                    <SalesTargetForm
                        v-else-if="editing.kind === 'form'"
                        :plan="editing.plan"
                        :fiscal-year="fiscalYear"
                        :months="months"
                        :ratios="ratioOf(editing.plan.ratio_category_id)"
                        @saved="onSaved"
                        @cancel="closeDialog"
                    />
                    <SalesTargetHistory v-else :fiscal-year="fiscalYear" :item-no="editing.plan.item_no" />
                </div>
            </div>
        </div>
    </div>
</template>
