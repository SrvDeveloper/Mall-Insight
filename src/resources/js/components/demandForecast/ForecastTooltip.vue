<script setup lang="ts">
import type { AverageWindow, ChannelForecast, SkuForecast } from "@/types/api";

/**
 * 需要予測の基準平均日販の内訳（計算の根拠、K-035〜K-037）。在庫推移のツールチップ（TrendTooltip）と同じ大きさ・色にそろえる。
 * チャネルごとに、3つの期間の平均日販と重みを1行ずつ示し、その下に範囲・販売数・販売日数・欠品日数を小さく添える。
 */

const props = defineProps<{
    sku: SkuForecast;
    /** 表示するチャネル（合計の表示ではすべて、チャネルの表示ではそのチャネルだけ） */
    channels: ChannelForecast[];
    /** 表示中のチャネル（または合計）の基準平均日販 */
    baseAverage: number | null;
    minSalesDays: number;
}>();

const formatAverage = (value: number): string => value.toFixed(2);
const shortDate = (value: string): string => `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`;

function range(window: AverageWindow): string {
    return window.from && window.to ? `${shortDate(window.from)}〜${shortDate(window.to)}` : "販売実績なし";
}

function excludedLabel(window: AverageWindow): string {
    return window.sales_days < props.minSalesDays ? `${props.minSalesDays}日未満` : "欠品が半分以上";
}
</script>

<template>
    <div class="flex items-center justify-between gap-3 border-b border-white/10 px-3 py-1.5">
        <span class="truncate font-mono text-xs font-medium text-white">{{ sku.sku_code }}</span>
        <span class="shrink-0 rounded-full bg-white/10 px-1.5 text-[10px] text-stone-200 tabular-nums"
            >基準平均日販 <strong class="font-semibold text-white">{{ baseAverage !== null ? formatAverage(baseAverage) : "予測不能" }}</strong></span
        >
    </div>

    <section
        v-for="channel in channels"
        :key="channel.channel"
        class="px-3 pt-1.5 pb-2 text-xs not-first-of-type:border-t not-first-of-type:border-white/10"
        :data-testid="`tooltip-${channel.channel}`"
    >
        <p class="flex items-baseline justify-between pb-1 text-[11px] font-semibold text-stone-300">
            {{ channel.channel_label }}
            <span class="tabular-nums" :class="channel.base_average !== null ? 'text-white' : 'text-amber-300'">{{
                channel.base_average !== null ? formatAverage(channel.base_average) : "予測不能"
            }}</span>
        </p>
        <ul class="flex flex-col gap-1">
            <li v-for="window in channel.windows" :key="window.length" class="grid grid-cols-[1fr_auto_2.75rem] items-baseline gap-x-2" data-testid="tooltip-window">
                <span :class="window.is_excluded ? 'text-stone-500' : 'text-stone-200'">{{ window.label }}</span>
                <span class="text-right tabular-nums" :class="window.average !== null ? 'text-white' : 'text-[11px] text-stone-500'">{{
                    window.average !== null ? formatAverage(window.average) : `除外（${excludedLabel(window)}）`
                }}</span>
                <span class="text-right text-[11px] text-stone-400 tabular-nums">×{{ window.weight.toFixed(2) }}</span>
                <span class="col-span-3 -mt-0.5 text-[10px] text-stone-500 tabular-nums">
                    {{ range(window) }}・{{ window.sales_quantity.toLocaleString() }}個÷{{ window.sales_days }}日<span v-if="window.stockout_days > 0" class="text-amber-300"
                        >・欠品{{ window.stockout_days }}日</span
                    >
                </span>
            </li>
        </ul>
        <p v-if="channel.unpredictable_reason_label" class="pt-1 text-[11px] text-amber-300">{{ channel.unpredictable_reason_label }}</p>
    </section>
    <p v-if="sku.channels.length === 0" class="px-3 py-2 text-[11px] text-amber-300">{{ sku.unpredictable_reason_label }}</p>
</template>
