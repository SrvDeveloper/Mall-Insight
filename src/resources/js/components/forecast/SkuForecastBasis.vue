<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import { MALL_SERIES } from "@/components/actuals/actualRows";
import { scopeChannels, type ForecastScope } from "@/components/forecast/timelineRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import type { SkuForecast } from "@/types/api";

/**
 * SKU別の需要予測（B-131、K-123）の、予測の根拠。画面デザイン案のとおり、チャネルごとの基準平均日販の根拠（重ならずに遡る3つの期間の販売数・
 * 販売日数・欠品日数・イベントの日数・平均日販・重み、K-036・K-037・K-112）と、月ごとの予測の内訳（普段の予測・季節係数・イベントで増やした数）を出す。
 * 予測できないチャネルは理由を出す。表示しているモールのチャネルだけを出し、内訳は合計する。
 */

const props = defineProps<{ detail: Omit<SkuForecast, "unit_prices">; scope: ForecastScope }>();

const channels = computed(() => props.detail.channels.filter((channel) => scopeChannels(props.scope).includes(channel.channel)));
const colorOf = (channel: "boss" | "amazon"): string => MALL_SERIES.find((mall) => mall.key === channel)!.color;
const formatAverage = (value: number | null): string => (value === null ? "—" : value.toFixed(2));
const shortRange = (from: string | null, to: string | null): string =>
    from && to ? `${Number(from.slice(5, 7))}/${Number(from.slice(8, 10))}〜${Number(to.slice(5, 7))}/${Number(to.slice(8, 10))}` : "販売実績なし";

/** 月ごとの内訳（予測できたチャネルの合計）。 */
const months = computed(() => {
    const predictable = channels.value.filter((channel) => channel.monthly !== null);
    const first = predictable[0]?.monthly ?? [];
    return first.map((month, index) => {
        const rows = predictable.map((channel) => channel.monthly![index]!);
        const events = [...new Set(rows.flatMap((row) => row.events.map((event) => event.name)))];
        const eventQuantity = rows.reduce((sum, row) => sum + row.event_quantity, 0);
        const total = rows.reduce((sum, row) => sum + row.quantity, 0);
        const monthNumber = Number(month.month.slice(5, 7));
        return {
            month: month.month,
            label: `${monthNumber === 1 || index === 0 ? `${month.month.slice(0, 4)}/` : ""}${monthNumber}月`,
            usual: total - eventQuantity,
            seasonal: month.seasonal_factor,
            eventText: eventQuantity > 0 ? `+${formatQuantity(eventQuantity)}（${events.map((name) => name.slice(0, 4)).join("・")}）` : "—",
            eventTitle: events.join("・"),
            hasEvent: eventQuantity > 0,
            total,
        };
    });
});
const hasSeasonal = computed(() => months.value.some((month) => month.seasonal !== null));
</script>

<template>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div v-for="channel in channels" :key="channel.channel" class="overflow-hidden rounded-[10px] border border-stone-200" :data-testid="`basis-${channel.channel}`">
            <div class="flex items-center justify-between gap-2 border-b border-stone-200 bg-stone-50 px-3 py-2.5">
                <h3 class="m-0 inline-flex items-center gap-1.5 text-[13px] font-semibold">
                    <span aria-hidden="true" class="size-2.5 rounded-[3px]" :style="{ background: colorOf(channel.channel) }" />{{ channel.channel_label }} の基準平均日販
                </h3>
                <strong class="text-lg tabular-nums" data-testid="basis-average">{{ formatAverage(channel.base_average) }}<span class="text-[11px] font-normal text-stone-500"> 個/日</span></strong>
            </div>
            <p v-if="channel.unpredictable_reason_label" class="m-0 border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="basis-reason">
                予測できません：{{ channel.unpredictable_reason_label }}
            </p>
            <table
                class="w-full border-collapse text-xs tabular-nums [&_td]:border-b [&_td]:border-stone-100 [&_td]:px-2.5 [&_td]:py-1.5 [&_th]:border-b [&_th]:border-stone-100 [&_th]:px-2.5 [&_th]:py-1.5"
            >
                <thead>
                    <tr class="[&_th]:border-stone-200 [&_th]:text-right [&_th]:font-medium [&_th]:text-stone-500">
                        <th scope="col" class="text-left!">期間</th>
                        <th scope="col">販売数</th>
                        <th scope="col">販売日数</th>
                        <th scope="col">欠品</th>
                        <th scope="col">イベント</th>
                        <th scope="col">平均日販</th>
                        <th scope="col">重み</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="window in channel.windows" :key="window.length" :class="window.is_excluded ? 'opacity-50' : ''" data-testid="basis-window">
                        <th scope="row" class="text-left font-normal text-stone-700">
                            {{ window.label }}<span class="block text-[10px] text-stone-400">{{ shortRange(window.from, window.to) }}</span>
                        </th>
                        <td class="text-right">{{ formatQuantity(window.sales_quantity) }}</td>
                        <td class="text-right">{{ window.sales_days }}日</td>
                        <td class="text-right" :class="window.stockout_days > 0 ? 'text-amber-700' : 'text-stone-600'">{{ window.stockout_days }}日</td>
                        <td class="text-right text-stone-600">{{ window.event_days }}日</td>
                        <td class="text-right font-semibold">{{ window.is_excluded ? "使わない" : formatAverage(window.average) }}</td>
                        <td class="text-right text-stone-600">{{ Math.round(window.weight * 100) }}%</td>
                    </tr>
                </tbody>
            </table>
            <p class="m-0 px-3 py-2 text-[11px] text-stone-500">販売数・販売日数は、欠品の日とイベントの日を除いた数です。</p>
        </div>
    </div>

    <div v-if="months.length > 0" class="overflow-hidden rounded-[10px] border border-stone-200" data-testid="monthly-breakdown">
        <div class="flex flex-wrap items-baseline gap-3 border-b border-stone-200 bg-stone-50 px-3 py-2.5">
            <h3 class="m-0 text-[13px] font-semibold">月ごとの予測の内訳</h3>
            <span class="text-[11px] text-stone-500">予測 ＝ 基準平均日販 × 日数（× 季節係数）＋ イベントで増やした数</span>
        </div>
        <div class="overflow-x-auto">
            <table
                class="w-full min-w-[720px] border-collapse text-xs tabular-nums [&_td]:border-b [&_td]:border-stone-100 [&_td]:px-2.5 [&_td]:py-1.5 [&_th]:border-b [&_th]:border-stone-100 [&_th]:px-2.5 [&_th]:py-1.5"
            >
                <thead>
                    <tr class="[&_th]:border-stone-200 [&_th]:text-right [&_th]:font-medium [&_th]:text-stone-500">
                        <th scope="col" class="text-left!">月</th>
                        <th v-for="month in months" :key="month.month" scope="col">{{ month.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row" class="text-left font-normal text-stone-600">普段の予測</th>
                        <td v-for="month in months" :key="month.month" class="text-right">{{ formatQuantity(month.usual) }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-normal text-stone-600">季節係数</th>
                        <td v-for="month in months" :key="month.month" class="text-right" :class="month.seasonal === null ? 'text-stone-400' : 'text-stone-900'">
                            {{ month.seasonal === null ? "—" : month.seasonal.toFixed(2) }}
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-normal text-stone-600">イベント</th>
                        <td
                            v-for="month in months"
                            :key="month.month"
                            class="text-right"
                            :class="month.hasEvent ? 'text-stone-900' : 'text-stone-400'"
                            :title="month.eventTitle || undefined"
                            data-testid="breakdown-event"
                        >
                            {{ month.eventText }}
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-semibold">予測</th>
                        <td v-for="month in months" :key="month.month" class="text-right font-bold" data-testid="breakdown-total">{{ formatQuantity(month.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="m-0 px-3 py-2 text-[11px] text-stone-500">
            <template v-if="hasSeasonal">季節係数は<RouterLink to="/seasonal-factors" class="text-stone-600">季節係数</RouterLink>の画面で直せます。</template>
            <template v-else>季節係数は登録していないため掛けていません（<RouterLink to="/seasonal-factors" class="text-stone-600">季節係数を登録する</RouterLink>）。</template>
            イベントの倍率は <RouterLink to="/sales-events" class="text-stone-600">イベント</RouterLink> の画面で直せます。
        </p>
    </div>
</template>
