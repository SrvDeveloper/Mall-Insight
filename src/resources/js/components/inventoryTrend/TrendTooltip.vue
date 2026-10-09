<script setup lang="ts">
import { computed } from "vue";
import { formatQuantity, isShort, longMonthLabel, type StockScope, type TrendTooltipContent } from "@/components/inventoryTrend/trendRows";

/**
 * 在庫推移のツールチップの中身（位置と外枠は呼び出し側が決める）。表を隠しすぎないよう、小さくまとめる。
 * - 月の欄：月初在庫 ＋ 入荷予定 − 需要（需要予測か販売目標、demandLabel） ＝ 月末在庫 の計算。足りない月は、月末在庫の行に赤い札で足りない数を出す。
 * - 現在庫：6区分の在庫を Amazon・BOSS・社内在庫の順に、量に比例した細い棒を付けて並べる。Amazon・BOSSの推移（B-121）では、
 *   推移に数えない区分（社内在庫など）を薄くし、参考として示す。
 */

const props = defineProps<{ content: TrendTooltipContent; stockDate: string | null; demandLabel: string; stockScope: StockScope }>();

const formatDate = (value: string): string => value.replaceAll("-", "/");

const WAREHOUSE_GROUPS: string[][] = [
    ["amazon_own", "amazon_fba"],
    ["boss_own", "boss_rfc"],
    ["free_stock", "ec_stock"],
];

/** 現在庫の内訳（Amazon・BOSS・社内在庫のまとまりごと、棒の長さは合計に対する割合） */
const stockGroups = computed(() => {
    if (props.content.kind !== "stock") {
        return [];
    }
    const sku = props.content.sku;
    const total = Math.max(
        (sku.stock_by_warehouse ?? []).reduce((sum, warehouse) => sum + Math.max(0, warehouse.quantity ?? 0), 0),
        1,
    );
    return WAREHOUSE_GROUPS.map((group) =>
        (sku.stock_by_warehouse ?? [])
            .filter((warehouse) => group.includes(warehouse.warehouse))
            .map((warehouse) => ({
                key: warehouse.warehouse,
                label: warehouse.label,
                text: warehouse.quantity === null ? "—" : formatQuantity(warehouse.quantity),
                width: `${(Math.max(0, warehouse.quantity ?? 0) / total) * 100}%`,
                inScope: props.stockScope.warehouses.includes(warehouse.warehouse),
            })),
    );
});

const coverMonths = computed(() => {
    if (props.content.kind !== "stock" || !props.content.sku.average_daily || props.content.sku.opening_stock === null) {
        return null;
    }
    return (props.content.sku.opening_stock / (props.content.sku.average_daily * 30)).toFixed(1);
});
</script>

<template>
    <div class="flex items-center justify-between gap-2 border-b border-white/10 px-3 py-1.5">
        <span class="truncate font-mono text-xs font-medium text-white">{{ content.sku.sku_code }}</span>
        <span class="flex shrink-0 items-center gap-1 text-[10px]">
            <template v-if="content.kind === 'month'">
                <span v-if="content.isCheckMonth" class="rounded-full bg-white px-1.5 font-semibold text-stone-900">判定</span>
                <span class="rounded-full bg-white/10 px-1.5 text-stone-200">{{ longMonthLabel(content.month.month) }}</span>
            </template>
            <span v-else class="rounded-full bg-white/10 px-1.5 text-stone-200">在庫 {{ stockDate ? formatDate(stockDate) : "—" }}</span>
        </span>
    </div>

    <dl v-if="content.kind === 'month'" class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5 px-3 pt-1.5 pb-2 text-xs tabular-nums">
        <dt class="text-stone-400">月初在庫</dt>
        <dd class="text-right">{{ formatQuantity(content.month.opening_stock) }}</dd>
        <dt class="text-stone-400">入荷予定</dt>
        <dd class="text-right">
            <span v-if="Math.round(content.month.provisional_inbound) >= 1" class="mr-1 text-[10px] text-amber-300">（仮{{ formatQuantity(content.month.provisional_inbound) }}）</span>+{{
                formatQuantity(content.month.inbound + content.month.provisional_inbound)
            }}
        </dd>
        <dt class="text-stone-400">
            {{ demandLabel }}<span class="text-[10px] text-stone-500">（{{ content.month.days }}日分）</span>
        </dt>
        <dd class="text-right">−{{ formatQuantity(content.month.demand) }}</dd>
        <dt class="mt-1 border-t border-white/10 pt-1 text-stone-300">月末在庫</dt>
        <dd class="mt-1 flex items-center justify-end gap-1.5 border-t border-white/10 pt-1 font-semibold text-white">
            <span v-if="isShort(content.month.shortfall)" class="rounded-full bg-red-500 px-1.5 text-[10px] font-semibold text-white" data-testid="tooltip-shortfall"
                >足りない {{ formatQuantity(content.month.shortfall) }}</span
            >
            {{ formatQuantity(content.month.ending_stock) }}
        </dd>
    </dl>

    <div v-else class="flex flex-col px-3 pt-1.5 pb-2 text-xs">
        <div v-for="(group, index) in stockGroups" :key="index" class="flex flex-col gap-0.5" :class="index > 0 ? 'mt-1 border-t border-white/10 pt-1' : ''">
            <div v-for="row in group" :key="row.key" class="grid grid-cols-[5.5rem_1fr_2.25rem] items-center gap-1.5" :class="row.inScope ? '' : 'opacity-40'" :data-in-scope="row.inScope">
                <span class="truncate text-[11px] text-stone-400">{{ row.label }}</span>
                <span class="h-1 rounded-full bg-white/10"><span class="block h-full rounded-full bg-stone-300" :style="{ width: row.width }" /></span>
                <span class="text-right tabular-nums">{{ row.text }}</span>
            </div>
        </div>
        <div class="mt-1.5 flex items-baseline justify-between border-t border-white/10 pt-1 tabular-nums">
            <span class="text-stone-300" data-testid="tooltip-stock-total"
                >{{ stockScope.isTotal ? "合計" : `${stockScope.label}の在庫` }} <strong class="ml-1 font-semibold text-white">{{ formatQuantity(content.sku.opening_stock ?? 0) }}</strong></span
            >
            <span v-if="coverMonths" class="text-[11px] text-stone-400">約{{ coverMonths }}か月分</span>
        </div>
    </div>
</template>
