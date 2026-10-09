<script setup lang="ts">
import type { SalesPeriod } from "@/api/actuals";
import SalesPeriodPicker from "@/components/actuals/SalesPeriodPicker.vue";
import type { ActualMeasure } from "@/components/actuals/actualRows";
import type { SalesView } from "@/components/actuals/useSalesActuals";
import type { SalesActualsResponse, SalesUnit } from "@/types/api";

/**
 * 品番別売上・SKU別売上の一覧の上の操作欄（月ごとと日ごと・数量と金額・表とグラフ・絞り込み・期間）と凡例。
 * 表とグラフの切り替えと絞り込みは、view・searchLabel を渡したときだけ出す（SKU別売上は1画面のため出さない）。
 */

defineProps<{ unit: SalesUnit; measure: ActualMeasure; view?: SalesView; rangeNote: string; searchLabel?: string; meta: SalesActualsResponse["meta"]; periodError: string | null }>();
const keyword = defineModel<string>("keyword", { default: "" });
const emit = defineEmits<{ update: [changes: { unit?: SalesUnit; measure?: ActualMeasure; view?: SalesView; period?: SalesPeriod }] }>();

const UNITS: { value: SalesUnit; label: string }[] = [
    { value: "month", label: "月ごと" },
    { value: "day", label: "日ごと" },
];

const MEASURES: { value: ActualMeasure; label: string }[] = [
    { value: "quantity", label: "数量" },
    { value: "amount", label: "金額" },
];

const VIEWS: { value: SalesView; label: string }[] = [
    { value: "table", label: "表" },
    { value: "graph", label: "グラフ" },
];
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="tablist" aria-label="月ごとと日ごと">
                <button
                    v-for="option in UNITS"
                    :key="option.value"
                    type="button"
                    role="tab"
                    class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                    :class="unit === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                    :aria-selected="unit === option.value"
                    :data-testid="`unit-${option.value}`"
                    @click="emit('update', { unit: option.value })"
                >
                    {{ option.label }}
                </button>
            </div>
            <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="数量と金額">
                <button
                    v-for="option in MEASURES"
                    :key="option.value"
                    type="button"
                    class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                    :class="measure === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                    :aria-pressed="measure === option.value"
                    :data-testid="`measure-${option.value}`"
                    @click="emit('update', { measure: option.value })"
                >
                    {{ option.label }}
                </button>
            </div>
            <div v-if="view" class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-[3px]" role="group" aria-label="見せ方">
                <button
                    v-for="option in VIEWS"
                    :key="option.value"
                    type="button"
                    class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                    :class="view === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                    :aria-pressed="view === option.value"
                    :data-testid="`view-${option.value}`"
                    @click="emit('update', { view: option.value })"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>
        <label v-if="searchLabel" class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
            <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <circle cx="7" cy="7" r="4.5" />
                <path d="m10.5 10.5 3 3" />
            </svg>
            <span class="sr-only">{{ searchLabel }}</span>
            <input v-model="keyword" type="search" :placeholder="searchLabel" class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400" />
        </label>
    </div>

    <div class="border-b border-stone-200 px-4 py-2.5">
        <SalesPeriodPicker :unit="unit" :from="meta.from" :to="meta.to" :today="meta.calculated_at.slice(0, 10)" :error="periodError" @change="emit('update', { period: $event })" />
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
        <span class="tabular-nums" data-testid="range-note">{{ rangeNote }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="text-stone-300">—</span>販売実績を取り込んでいない{{ unit === "day" ? "日" : "月" }}</span>
        <span class="text-stone-400">期間計は選んだ期間の合計です</span>
        <span v-if="measure === 'amount'" class="text-stone-500" data-testid="amount-note">金額は取り込んだ販売実績の金額（税込）の合計で、千円単位です</span>
    </div>
</template>
