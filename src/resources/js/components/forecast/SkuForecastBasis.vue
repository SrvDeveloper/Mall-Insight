<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import { MALL_SERIES } from "@/components/actuals/actualRows";
import { formatFigure, scopeChannels, type ForecastScope, type TimelineLabel } from "@/components/forecast/timelineRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import type { AverageWindow, ChannelForecast, ForecastTimelineColumn, ForecastTimelineResponse, ForecastTimelineSku, SalesUnit, SkuForecast } from "@/types/api";

/**
 * SKU別の需要予測（B-131、K-123）の、予測の根拠。画面デザイン案のとおり、チャネルごとの基準平均日販の根拠（重ならずに遡る3つの期間の販売数・
 * 販売日数・欠品日数・イベントの日数・平均日販・重み、K-036・K-037・K-112）と、月ごとの予測の内訳（普段の予測・季節係数・イベントで増やした数）を出す。
 * BOSS は、基準平均日販をモール（楽天市場・Yahoo!ショッピング・au PAY マーケット）ごとに求めて足すため、期間もモールごとに出す（B-134、K-126）。
 * 日ごとのときは、月ごとの代わりに今日から先の日ごとの予測の内訳（普段の予測・季節係数・イベントで増やした数）を出す。
 * 予測できないチャネルは理由を出す。表示しているモールのチャネルだけを出し、内訳は合計する。
 */

const props = defineProps<{
    detail: Omit<SkuForecast, "unit_prices">;
    scope: ForecastScope;
    unit: SalesUnit;
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    /** 月・日に重なるイベントの期間（表示しているモールのもの） */
    events: ForecastTimelineResponse["meta"]["events"][];
    /** SKUのチャネルごとの、月・日ごとの予測 */
    timeline: ForecastTimelineSku["channels"];
}>();
const isDay = computed(() => props.unit === "day");

const channels = computed(() => props.detail.channels.filter((channel) => scopeChannels(props.scope).includes(channel.channel)));

/** チャネルの期間を、モールごとにまとめる（モールが1つのチャネルは見出しを出さない）。 */
const groupsOf = (channel: ChannelForecast): { key: string; label: string | null; average: number | null; note: string | null; windows: AverageWindow[] }[] =>
    channel.malls.length > 1
        ? channel.malls.map((mall) => ({
              key: mall.mall,
              label: mall.mall_label,
              average: mall.base_average,
              note: mall.unpredictable_reason === "no_sales" ? "売れていない（0として足す）" : mall.unpredictable_reason_label,
              windows: mall.unpredictable_reason === "no_sales" ? [] : mall.windows,
          }))
        : [{ key: channel.channel, label: null, average: null, note: null, windows: channel.windows }];
const colorOf = (channel: "boss" | "amazon"): string => MALL_SERIES.find((mall) => mall.key === channel)!.color;
const formatAverage = (value: number | null): string => (value === null ? "—" : value.toFixed(2));
const shortRange = (from: string | null, to: string | null): string =>
    from && to ? `${Number(from.slice(5, 7))}/${Number(from.slice(8, 10))}〜${Number(to.slice(5, 7))}/${Number(to.slice(8, 10))}` : "販売実績なし";

interface BreakdownColumn {
    key: string;
    label: string;
    /** 日ごとの曜日 */
    sub: string;
    usual: string;
    seasonal: number | null;
    eventText: string;
    eventTitle: string;
    hasEvent: boolean;
    total: string;
}

const predictable = computed(() => channels.value.filter((channel) => channel.monthly !== null));
const eventText = (quantity: number, names: string[], format: (value: number) => string): string =>
    quantity > 0 ? `+${format(quantity)}（${names.map((name) => name.slice(0, 4)).join("・")}）` : "—";

/** 月ごとの内訳（予測できたチャネルの合計）。 */
const months = computed<BreakdownColumn[]>(() => {
    const first = predictable.value[0]?.monthly ?? [];
    return first.map((month, index) => {
        const rows = predictable.value.map((channel) => channel.monthly![index]!);
        const events = [...new Set(rows.flatMap((row) => row.events.map((event) => event.name)))];
        const eventQuantity = rows.reduce((sum, row) => sum + row.event_quantity, 0);
        const total = rows.reduce((sum, row) => sum + row.quantity, 0);
        const monthNumber = Number(month.month.slice(5, 7));
        return {
            key: month.month,
            label: `${monthNumber === 1 || index === 0 ? `${month.month.slice(0, 4)}/` : ""}${monthNumber}月`,
            sub: "",
            usual: formatQuantity(total - eventQuantity),
            seasonal: month.seasonal_factor,
            eventText: eventText(eventQuantity, events, formatQuantity),
            eventTitle: events.join("・"),
            hasEvent: eventQuantity > 0,
            total: formatQuantity(total),
        };
    });
});

/** 日ごとの内訳（今日から先の日、予測できたチャネルの合計）。予測＝基準平均日販×その月の季節係数×イベントの倍率。 */
const days = computed<BreakdownColumn[]>(() => {
    const formatDay = (value: number): string => formatFigure(value, "quantity", "day");
    const result: BreakdownColumn[] = [];
    props.columns.forEach((column, index) => {
        if (column.kind === "past") {
            return;
        }
        let total = 0;
        let eventQuantity = 0;
        const multipliers: string[] = [];
        for (const channel of predictable.value) {
            const forecast = props.timeline[channel.channel]?.forecast[index];
            if (forecast === null || forecast === undefined) {
                continue;
            }
            const event = props.timeline[channel.channel]?.event[index] ?? 0;
            total += forecast;
            eventQuantity += event;
            // 倍率は月ごとの内訳から取る（BOSS は倍率をイベントのモールの分にだけ掛けるため、予測÷普段の予測とは合わない）
            const monthEvents = channel.monthly?.find((month) => month.month === column.key.slice(0, 7))?.events ?? [];
            for (const event of props.events[index] ?? []) {
                const multiplier = monthEvents.find((item) => item.period_id === event.id)?.multiplier;
                if (multiplier !== undefined) {
                    multipliers.push(`${event.name} ${channel.channel_label} ×${multiplier.toFixed(2)}`);
                }
            }
        }
        const events = [...new Set((props.events[index] ?? []).map((event) => event.name))];
        const label = props.labels[index]!;
        result.push({
            key: column.key,
            label: label.label,
            sub: label.sub,
            usual: formatDay(total - eventQuantity),
            seasonal: predictable.value[0]?.monthly?.find((month) => month.month === column.key.slice(0, 7))?.seasonal_factor ?? null,
            eventText: eventText(eventQuantity, events, formatDay),
            eventTitle: (multipliers.length > 0 ? multipliers : events).join("　"),
            hasEvent: eventQuantity > 0,
            total: formatDay(total),
        });
    });
    return result;
});

const breakdown = computed(() => (isDay.value ? days.value : months.value));
const hasSeasonal = computed(() => breakdown.value.some((column) => column.seasonal !== null));
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
                <tbody v-for="group in groupsOf(channel)" :key="group.key" :data-testid="`basis-mall-${group.key}`">
                    <tr v-if="group.label" class="bg-stone-50/60">
                        <th scope="rowgroup" colspan="5" class="text-left font-semibold text-stone-800">
                            {{ group.label }}<span v-if="group.note" class="ml-2 text-[11px] font-normal text-stone-500" data-testid="basis-mall-note">{{ group.note }}</span>
                        </th>
                        <td class="text-right font-semibold" data-testid="basis-mall-average">{{ formatAverage(group.average) }}</td>
                        <td />
                    </tr>
                    <tr v-for="window in group.windows" :key="window.length" :class="window.is_excluded ? 'opacity-50' : ''" data-testid="basis-window">
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
            <p class="m-0 px-3 py-2 text-[11px] text-stone-500">
                販売数・販売日数は、欠品の日とイベントの日を除いた数です。<template v-if="channel.malls.length > 1"
                    >基準平均日販はモールごとに求めて足します。イベントの日は、そのイベントのモールでだけ除き、倍率もそのモールの分にだけ掛けます。</template
                >
            </p>
        </div>
    </div>

    <div v-if="breakdown.length > 0" class="overflow-hidden rounded-[10px] border border-stone-200" :data-testid="isDay ? 'daily-breakdown' : 'monthly-breakdown'">
        <div class="flex flex-wrap items-baseline gap-3 border-b border-stone-200 bg-stone-50 px-3 py-2.5">
            <h3 class="m-0 text-[13px] font-semibold">{{ isDay ? "日ごと" : "月ごと" }}の予測の内訳</h3>
            <span class="text-[11px] text-stone-500">{{
                isDay ? "予測 ＝ 基準平均日販（× 季節係数）＋ イベントで増やした数（イベントの倍率で増やした分）" : "予測 ＝ 基準平均日販 × 日数（× 季節係数）＋ イベントで増やした数"
            }}</span>
        </div>
        <div class="overflow-x-auto">
            <table
                class="w-full min-w-[720px] border-collapse text-xs tabular-nums [&_td]:border-b [&_td]:border-stone-100 [&_td]:px-2.5 [&_td]:py-1.5 [&_th]:border-b [&_th]:border-stone-100 [&_th]:px-2.5 [&_th]:py-1.5"
            >
                <thead>
                    <tr class="[&_th]:border-stone-200 [&_th]:text-right [&_th]:font-medium [&_th]:text-stone-500">
                        <th scope="col" class="text-left!">{{ isDay ? "日" : "月" }}</th>
                        <th v-for="column in breakdown" :key="column.key" scope="col" class="whitespace-nowrap">
                            {{ column.label }}<span v-if="column.sub" class="block text-[10px] font-normal text-stone-400">{{ column.sub }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row" class="text-left font-normal whitespace-nowrap text-stone-600">普段の予測</th>
                        <td v-for="column in breakdown" :key="column.key" class="text-right">{{ column.usual }}</td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-normal whitespace-nowrap text-stone-600">季節係数</th>
                        <td v-for="column in breakdown" :key="column.key" class="text-right" :class="column.seasonal === null ? 'text-stone-400' : 'text-stone-900'">
                            {{ column.seasonal === null ? "—" : column.seasonal.toFixed(2) }}
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-normal whitespace-nowrap text-stone-600">イベント</th>
                        <td
                            v-for="column in breakdown"
                            :key="column.key"
                            class="text-right whitespace-nowrap"
                            :class="column.hasEvent ? 'text-stone-900' : 'text-stone-400'"
                            :title="column.eventTitle || undefined"
                            data-testid="breakdown-event"
                        >
                            {{ column.eventText }}
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-left font-semibold">予測</th>
                        <td v-for="column in breakdown" :key="column.key" class="text-right font-bold" data-testid="breakdown-total">{{ column.total }}</td>
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
