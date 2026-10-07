<script setup lang="ts">
import { computed, ref } from "vue";
import { monthLabel, type ItemRows, type TrendMonth } from "@/components/inventoryTrend/trendRows";

/**
 * 在庫推移の「グラフ」（K-047、デザイン案A-3）。品番ごとのカードに、SKUの12か月の在庫の増減を小さなグラフで並べる。
 * 在庫は灰色の面と線、足りない量は0の線より下の赤い面、判定する月は点線、入荷予定は ▲ で示す。
 * 判定する月に欠品するSKUは、カードの枠を薄い赤にする。
 * 1行に並べるSKUの数は、品番ごとに選べる（自動・2〜6）。選んだ数はブラウザに記憶する（閲覧者ごとの好みのため）。
 */

const props = defineProps<{ items: ItemRows[]; months: TrendMonth[]; checkMonthIndex: number }>();

/** グラフの下に出す月（最初の月・判定する月・最後の月） */
const axisLabels = computed(() => {
    const first = props.months[0];
    const check = props.months[props.checkMonthIndex];
    const last = props.months.at(-1);
    return { first: first ? monthLabel(first.month) : "", check: check ? monthLabel(check.month) : "", last: last ? monthLabel(last.month) : "" };
});
const checkPosition = computed(() => `${((props.checkMonthIndex + 1) / Math.max(1, props.months.length)) * 100}%`);

/** 1行に並べるSKUの数の選択肢。0 は画面の幅に合わせる（自動）。 */
const COLUMN_OPTIONS = [0, 2, 3, 4, 5, 6];
const STORAGE_KEY = "mall-insight.inventory-trend.graph-columns";

function loadColumns(): Record<string, number> {
    try {
        const saved: unknown = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? "{}");
        return saved && typeof saved === "object" ? (saved as Record<string, number>) : {};
    } catch {
        return {};
    }
}

const columnsByItem = ref<Record<string, number>>(loadColumns());

function columnsOf(itemNo: string): number {
    const value = columnsByItem.value[itemNo];
    return value !== undefined && COLUMN_OPTIONS.includes(value) ? value : 0;
}

function selectColumns(itemNo: string, columns: number): void {
    columnsByItem.value = { ...columnsByItem.value, [itemNo]: columns };
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(columnsByItem.value));
    } catch {
        // 記憶できない環境（プライベートブラウズなど）では、この画面を開いている間だけ有効にする
    }
}

function gridStyle(itemNo: string): Record<string, string> {
    const columns = columnsOf(itemNo);
    return { gridTemplateColumns: columns === 0 ? "repeat(auto-fill, minmax(14.5rem, 1fr))" : `repeat(${columns}, minmax(0, 1fr))` };
}
</script>

<template>
    <div class="flex flex-col gap-4 p-4" data-testid="trend-graph">
        <section v-for="group in items" :key="group.item.item_no" class="flex flex-col gap-3 rounded-xl border border-stone-200 bg-white p-4" data-testid="graph-item">
            <div class="flex flex-wrap items-center gap-2.5">
                <h3 class="font-mono text-base font-medium text-stone-900">{{ group.item.item_no }}</h3>
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ group.item.brand }}・{{ group.item.category }}</span>
                <span class="text-xs text-stone-500"
                    >現在庫 <strong class="text-stone-900 tabular-nums">{{ group.stockText }}</strong></span
                >
                <span v-if="group.shortageCount > 0" class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700">
                    判定する月に欠品 {{ group.shortageCount }}/{{ group.calculatedCount }} SKU
                </span>
                <template v-for="(inbound, index) in group.monthInbounds" :key="index">
                    <span v-if="inbound" class="inline-flex items-center gap-1 rounded-full border border-stone-300 px-2 py-px text-xs text-stone-700 tabular-nums" :title="inbound.label">
                        <svg class="size-3 text-stone-500" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.5 6.5v6.5h11V6.5" />
                            <path d="M1.5 6.5h13" />
                            <path d="M8 1.5v6M5.5 5 8 7.5 10.5 5" />
                        </svg>
                        {{ monthLabel(months[index]!.month) }} {{ inbound.text }}
                    </span>
                </template>
                <span v-if="group.item.unassignable_inbound > 0" class="text-xs text-amber-700">入荷を割り振れません</span>
                <div class="ml-auto flex items-center gap-1.5 text-[11px] text-stone-500">
                    <span aria-hidden="true">1行に</span>
                    <div class="inline-flex gap-0.5 rounded-md bg-stone-100 p-0.5" role="group" :aria-label="`${group.item.item_no} の1行に並べるSKUの数`" data-testid="graph-columns">
                        <button
                            v-for="columns in COLUMN_OPTIONS"
                            :key="columns"
                            type="button"
                            class="h-6 min-w-7 rounded px-1.5 tabular-nums"
                            :class="columnsOf(group.item.item_no) === columns ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-900'"
                            :aria-pressed="columnsOf(group.item.item_no) === columns"
                            @click="selectColumns(group.item.item_no, columns)"
                        >
                            {{ columns === 0 ? "自動" : columns }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid gap-3" :style="gridStyle(group.item.item_no)" data-testid="graph-grid">
                <div
                    v-for="row in group.rows"
                    :key="row.sku.sku_id"
                    class="flex flex-col gap-2 rounded-lg border bg-white px-3 pt-3 pb-2.5"
                    :class="row.sku.warning === 'shortage' ? 'border-red-200' : 'border-stone-200'"
                    data-testid="graph-tile"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-mono text-[12.5px] text-stone-900" :title="row.sku.sku_code">{{ row.sku.sku_code }}</span>
                        <span
                            class="shrink-0 rounded-full px-2 py-px text-[11px] font-semibold"
                            :class="row.status ? (row.status.isShortage ? 'bg-red-100 text-red-700' : 'bg-stone-100 text-stone-600') : 'bg-stone-100 text-stone-500'"
                            data-testid="graph-status"
                        >
                            {{ row.status?.label ?? "計算できない" }}
                        </span>
                    </div>
                    <template v-if="row.sparkline">
                        <div class="flex items-baseline gap-1.5 text-[11px] text-stone-500">
                            <span class="rounded-full bg-stone-100 px-2 py-px text-xs font-semibold text-stone-900 tabular-nums">在庫 {{ row.stockText }}</span>
                            <span v-if="row.sku.average_daily !== null">1日 約{{ row.sku.average_daily.toFixed(2) }}個</span>
                            <span v-if="row.sku.is_partial_forecast" class="font-medium text-amber-700" :title="row.sku.forecast_reason_label ?? undefined">需要一部</span>
                        </div>
                        <svg viewBox="0 0 240 72" preserveAspectRatio="none" class="block h-[72px] w-full" role="img" :aria-label="`${row.sku.sku_code} の12か月の在庫の推移`">
                            <defs>
                                <clipPath :id="`trend-spark-${row.sku.sku_id}-above`"><rect x="0" y="0" width="240" :height="row.sparkline.zeroY" /></clipPath>
                                <clipPath :id="`trend-spark-${row.sku.sku_id}-below`"><rect x="0" :y="row.sparkline.zeroY" width="240" height="72" /></clipPath>
                            </defs>
                            <path :d="row.sparkline.area" class="fill-stone-200" :clip-path="`url(#trend-spark-${row.sku.sku_id}-above)`" />
                            <path :d="row.sparkline.area" class="fill-red-300" :clip-path="`url(#trend-spark-${row.sku.sku_id}-below)`" data-testid="graph-shortage-area" />
                            <line x1="0" x2="240" :y1="row.sparkline.zeroY" :y2="row.sparkline.zeroY" class="stroke-stone-400" stroke-width="1" />
                            <line :x1="row.sparkline.checkX" :x2="row.sparkline.checkX" y1="2" y2="70" class="stroke-stone-500" stroke-width="1.2" stroke-dasharray="3 3" />
                            <path :d="row.sparkline.line" fill="none" class="stroke-stone-900" stroke-width="1.6" stroke-linejoin="round" />
                            <path v-for="(mark, index) in row.sparkline.inboundMarks" :key="index" :d="mark" class="fill-stone-500" data-testid="graph-inbound" />
                        </svg>
                        <div class="relative h-3.5 text-[10px] text-stone-400">
                            <span class="absolute left-0">{{ axisLabels.first }}</span>
                            <span class="absolute -translate-x-1/2 font-semibold text-stone-700" :style="{ left: checkPosition }">{{ axisLabels.check }} 判定</span>
                            <span class="absolute right-0">{{ axisLabels.last }}</span>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex h-[7.5rem] items-center justify-center rounded-md bg-[repeating-linear-gradient(135deg,var(--color-stone-50)_0_8px,var(--color-stone-100)_8px_16px)] px-3 text-center text-xs text-stone-500"
                        :title="row.sku.forecast_reason_label ?? row.sku.status_label"
                    >
                        {{ row.sku.status_label }}
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
