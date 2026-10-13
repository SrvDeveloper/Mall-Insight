<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watchPostEffect } from "vue";
import { MALL_SERIES } from "@/components/actuals/actualRows";
import { CHANNELS, formatVariance, type ForecastChannel, type TimelineFigures, type TimelineLabel } from "@/components/forecast/timelineRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面（B-131、K-123）のグラフ。月・日ごとに、過去は販売実績、今月（今日）から先は需要予測を、モールごとの積み上げ棒で出す。
 * 需要予測は斜線で、今月は前日までの販売実績の上に残りの予測を積む（今月の予測は1か月分、K-052）。過去の月・日には予測し直した数を
 * 横線で重ね、今日の位置に点線を引く。日ごとは、上にイベントの期間の帯を出し、土日と欠品した日の地を変える。
 * 棒の作り方（太さ・角丸・積み上げの隙間・目盛り）と、マウスを乗せたときのツールチップは、販売実績のグラフ（SalesChart）とそろえる。
 */

const props = defineProps<{
    columns: ForecastTimelineColumn[];
    labels: TimelineLabel[];
    figures: TimelineFigures;
    events: ForecastTimelineResponse["meta"]["events"][];
    unit: SalesUnit;
    threshold: number;
    formatValue: (value: number) => string;
    formatAxis: (value: number) => string;
    axisUnit: string;
    height?: number;
}>();

const isDay = computed(() => props.unit === "day");
const MARGIN = computed(() => ({ top: isDay.value ? 40 : 16, right: 12, bottom: 40, left: 64 }));
const HEIGHT = computed(() => props.height ?? 300);
const MAX_BAR = 24;
const GAP = 2;
const RADIUS = 4;

const root = useTemplateRef<HTMLDivElement>("root");
const graph = useTemplateRef("graph");
const tooltipElement = useTemplateRef<HTMLDivElement>("tooltipElement");
const tooltipPosition = ref({ left: 0, top: 0 });
const width = ref(760);
let observer: ResizeObserver | null = null;
onMounted(() => {
    if (!root.value || typeof ResizeObserver === "undefined") {
        return;
    }
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(320, Math.floor(entry!.contentRect.width));
    });
    observer.observe(root.value);
});
onBeforeUnmount(() => observer?.disconnect());

const plotWidth = computed(() => width.value - MARGIN.value.left - MARGIN.value.right);
const plotHeight = computed(() => HEIGHT.value - MARGIN.value.top - MARGIN.value.bottom);
const band = computed(() => plotWidth.value / Math.max(1, props.columns.length));
const barWidth = computed(() => Math.max(2, Math.min(MAX_BAR, band.value * 0.68)));
const xLeft = (index: number): number => MARGIN.value.left + band.value * index;
const xCenter = (index: number): number => xLeft(index) + band.value / 2;

/** 列ごとの、チャネルの積み上げ（販売実績と、斜線の需要予測）。 */
const parts = computed(() =>
    props.columns.map((column, index) => {
        const solid: { channel: ForecastChannel; value: number }[] = [];
        const hatched: { channel: ForecastChannel; value: number }[] = [];
        for (const channel of CHANNELS) {
            const series = props.figures.channels[channel];
            if (!series) {
                continue;
            }
            const actual = column.kind === "future" ? 0 : (series.actual[index] ?? 0);
            const forecast = series.forecast[index] ?? 0;
            if (actual > 0) {
                solid.push({ channel, value: actual });
            }
            const rest = column.kind === "past" ? 0 : Math.max(0, forecast - actual);
            if (rest > 0) {
                hatched.push({ channel, value: rest });
            }
        }
        return [...solid.map((part) => ({ ...part, hatched: false })), ...hatched.map((part) => ({ ...part, hatched: true }))];
    }),
);

const ticks = computed(() => {
    const peaks = parts.value.map((list, index) =>
        Math.max(
            list.reduce((total, part) => total + part.value, 0),
            props.figures.replayed[index] ?? 0,
        ),
    );
    const max = Math.max(0, ...peaks);
    if (max <= 0) {
        return [0, 1];
    }
    const rough = max / 4;
    const power = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((factor) => factor * power).find((candidate) => candidate >= rough)!;
    return Array.from({ length: Math.ceil(max / step) + 1 }, (_, index) => index * step);
});
const yMax = computed(() => ticks.value.at(-1)!);
const y = (value: number): number => MARGIN.value.top + plotHeight.value - (value / yMax.value) * plotHeight.value;

const colorOf = (channel: ForecastChannel): string => MALL_SERIES.find((mall) => mall.key === channel)!.color;

/** 棒の部分（下から積む）。一番上の部分だけ上端を角丸にし、部分の間は白い隙間で分ける。 */
const stacks = computed(() =>
    parts.value.map((list, index) => {
        let base = 0;
        return list.flatMap((part, partIndex) => {
            const top = base + part.value;
            const x0 = xCenter(index) - barWidth.value / 2;
            const x1 = x0 + barWidth.value;
            const yBottom = y(base) - (partIndex > 0 ? GAP : 0);
            const yTop = y(top);
            base = top;
            if (yBottom - yTop < 0.5) {
                return [];
            }
            const isTop = partIndex === list.length - 1;
            const r = isTop ? Math.min(RADIUS, (yBottom - yTop) / 2, barWidth.value / 2) : 0;
            const path = isTop
                ? `M ${x0} ${yBottom} L ${x0} ${yTop + r} Q ${x0} ${yTop} ${x0 + r} ${yTop} L ${x1 - r} ${yTop} Q ${x1} ${yTop} ${x1} ${yTop + r} L ${x1} ${yBottom} Z`
                : `M ${x0} ${yBottom} L ${x0} ${yTop} L ${x1} ${yTop} L ${x1} ${yBottom} Z`;
            return [
                {
                    key: `${part.channel}-${part.hatched ? "forecast" : "actual"}`,
                    fill: part.hatched ? `url(#hatch-${part.channel})` : colorOf(part.channel),
                    stroke: part.hatched ? colorOf(part.channel) : "none",
                    path,
                },
            ];
        });
    }),
);

/** 予測し直した数の横線（棒より少し広く、白い縁で棒と分ける）。 */
const replayTicks = computed(() =>
    props.columns.flatMap((column, index) => {
        const value = props.figures.replayed[index];
        if (column.kind !== "past" || value === null || value === undefined) {
            return [];
        }
        const half = Math.max(barWidth.value / 2 + 3, Math.min(band.value / 2 - 1, barWidth.value / 2 + 6));
        return [{ key: column.key, x1: xCenter(index) - half, x2: xCenter(index) + half, y: y(value) }];
    }),
);

/** 今日（今月）の位置の点線。 */
const todayX = computed(() => {
    const index = props.columns.findIndex((column) => column.kind === "current");
    return index < 0 ? null : xLeft(index);
});

/** 日ごとの、土日と欠品した日の地。 */
const shades = computed(() =>
    !isDay.value
        ? []
        : props.columns.flatMap((column, index) => {
              if (props.figures.stockout[index] && column.kind === "past") {
                  return [{ key: column.key, x: xLeft(index), className: "fill-amber-100" }];
              }
              return props.labels[index]?.weekend ? [{ key: column.key, x: xLeft(index), className: "fill-stone-100/70" }] : [];
          }),
);

/** 日ごとの、イベントの期間の帯（続いた日をまとめる）。同じ日に重なる期間は段を分ける。 */
const eventBands = computed(() => {
    if (!isDay.value) {
        return [];
    }
    const bands: { key: string; name: string; channel: ForecastChannel; first: number; last: number; row: number }[] = [];
    props.events.forEach((events, index) => {
        for (const event of events) {
            const current = bands.find((item) => item.key === String(event.id) && item.last === index - 1);
            if (current) {
                current.last = index;
            } else {
                bands.push({ key: String(event.id), name: event.name, channel: event.channels[0] ?? "boss", first: index, last: index, row: 0 });
            }
        }
    });
    bands.forEach((item, position) => {
        const overlapping = bands.slice(0, position).filter((other) => other.first <= item.last && other.last >= item.first);
        item.row = overlapping.some((other) => other.row === 0) ? 1 : 0;
    });
    return bands.map((item) => ({ ...item, x: xLeft(item.first) + 1, width: band.value * (item.last - item.first + 1) - 2, y: 4 + item.row * 16 }));
});

/** 横軸の見出し。日ごとは今日と毎週月曜（今日の前後3日の月曜は今日と重なるため出さない）、月ごとはすべて。 */
const xLabels = computed(() =>
    props.columns.map((column, index) => {
        const label = props.labels[index]!;
        if (!isDay.value) {
            return { key: column.key, index, show: true, label: label.label, sub: label.sub };
        }
        const today = props.columns.findIndex((item) => item.kind === "current");
        const isMonday = label.sub === "月";
        return { key: column.key, index, show: index === today || (isMonday && (today < 0 || Math.abs(index - today) >= 4)), label: label.label, sub: "" };
    }),
);

// ツールチップ
const hovered = ref<number | null>(null);

const tooltip = computed(() => {
    if (hovered.value === null) {
        return null;
    }
    const index = hovered.value;
    const column = props.columns[index]!;
    const rows: { label: string; value: string; tone?: "warning" | "muted" }[] = [];
    for (const channel of CHANNELS) {
        const series = props.figures.channels[channel];
        if (!series) {
            continue;
        }
        const name = MALL_SERIES.find((mall) => mall.key === channel)!.label;
        const actual = series.actual[index];
        if (column.kind !== "future") {
            rows.push({ label: `${name} の${column.kind === "current" && !isDay.value ? "前日までの" : ""}実績`, value: actual === null || actual === undefined ? "—" : props.formatValue(actual) });
        }
        if (column.kind !== "past") {
            const forecast = series.forecast[index];
            rows.push({ label: `${name} の予測`, value: forecast === null || forecast === undefined ? "予測できません" : props.formatValue(forecast) });
        }
    }
    if (column.kind === "past") {
        const replayed = props.figures.replayed[index];
        rows.push({ label: "予測し直した数", value: replayed === null || replayed === undefined ? "予測できません" : props.formatValue(replayed) });
        const variance = props.figures.variance[index];
        if (isDay.value && props.figures.stockout[index]) {
            rows.push({ label: "差異", value: "欠品した日は数えません", tone: "warning" });
        } else if (variance !== null && variance !== undefined) {
            rows.push({ label: "差異", value: formatVariance(variance), tone: Math.abs(variance) >= props.threshold ? "warning" : undefined });
        }
    }
    for (const event of props.events[index] ?? []) {
        rows.push({ label: "イベント", value: event.name, tone: "muted" });
    }
    const suffix = column.kind === "current" ? (isDay.value ? "（今日）" : "（今月）") : column.kind === "future" ? "（予測）" : "";
    return { title: `${props.labels[index]!.title}${suffix}`, rows, anchorX: xCenter(index) };
});

watchPostEffect(() => {
    if (!tooltip.value || !root.value || !graph.value || !tooltipElement.value) {
        return;
    }
    const chartBounds = root.value.getBoundingClientRect();
    const graphBounds = graph.value.getBoundingClientRect();
    const tooltipWidth = tooltipElement.value.getBoundingClientRect().width || 256;
    const chartWidth = chartBounds.width || width.value;
    const left = Math.min(Math.max(0, tooltip.value.anchorX - tooltipWidth / 2), Math.max(0, chartWidth - tooltipWidth));
    tooltipPosition.value = { left, top: Math.max(0, graphBounds.top - chartBounds.top + MARGIN.value.top) };
});
</script>

<template>
    <div ref="root" class="relative" data-testid="forecast-chart">
        <svg ref="graph" :width="width" :height="HEIGHT" class="block max-w-full" role="img" :aria-label="`${axisUnit}の販売実績と需要予測`" @mouseleave="hovered = null">
            <defs>
                <pattern v-for="channel in CHANNELS" :id="`hatch-${channel}`" :key="channel" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                    <rect width="6" height="6" :fill="colorOf(channel)" fill-opacity="0.22" />
                    <rect width="3" height="6" :fill="colorOf(channel)" fill-opacity="0.65" />
                </pattern>
            </defs>

            <!-- 土日と欠品した日の地 -->
            <rect v-for="shade in shades" :key="`shade-${shade.key}`" :x="shade.x" :y="MARGIN.top" :width="band" :height="plotHeight" :class="shade.className" data-testid="chart-shade" />

            <!-- 目盛り線と縦軸 -->
            <g class="text-[11px]">
                <template v-for="tick in ticks" :key="tick">
                    <line :x1="MARGIN.left" :x2="width - MARGIN.right" :y1="y(tick)" :y2="y(tick)" :class="tick === 0 ? 'stroke-stone-300' : 'stroke-stone-200'" stroke-width="1" />
                    <text :x="MARGIN.left - 8" :y="y(tick)" text-anchor="end" dominant-baseline="middle" class="fill-stone-500 tabular-nums">{{ formatAxis(tick) }}</text>
                </template>
            </g>

            <!-- イベントの期間の帯（日ごと） -->
            <g v-for="item in eventBands" :key="`band-${item.key}-${item.first}`" data-testid="event-band">
                <title>{{ item.name }}</title>
                <rect :x="item.x" :y="item.y" :width="Math.max(4, item.width)" height="14" rx="4" class="fill-white stroke-stone-300" stroke-width="1" />
                <circle :cx="item.x + 7" :cy="item.y + 7" r="2.5" :fill="colorOf(item.channel)" />
                <text v-if="item.width > 28" :x="item.x + 13" :y="item.y + 10.5" class="fill-stone-600 text-[10px]">{{ item.width > 70 ? item.name : item.name.slice(0, 4) }}</text>
            </g>

            <!-- マウスを乗せた月・日 -->
            <rect v-if="hovered !== null" :x="xLeft(hovered)" :y="MARGIN.top" :width="band" :height="plotHeight" class="fill-stone-900/5" />

            <!-- 棒 -->
            <g v-for="(segments, index) in stacks" :key="columns[index]!.key">
                <path
                    v-for="segment in segments"
                    :key="segment.key"
                    :d="segment.path"
                    :fill="segment.fill"
                    :stroke="segment.stroke"
                    stroke-width="0.75"
                    data-testid="chart-segment"
                    :data-segment="segment.key"
                />
            </g>

            <!-- 予測し直した数 -->
            <line
                v-for="tick in replayTicks"
                :key="`replay-${tick.key}`"
                :x1="tick.x1"
                :x2="tick.x2"
                :y1="tick.y"
                :y2="tick.y"
                class="stroke-stone-900"
                stroke-width="2.5"
                stroke-linecap="round"
                paint-order="stroke"
                data-testid="replay-tick"
            />

            <!-- 今日の位置 -->
            <line
                v-if="todayX !== null"
                :x1="todayX"
                :x2="todayX"
                :y1="MARGIN.top"
                :y2="MARGIN.top + plotHeight"
                class="stroke-stone-400"
                stroke-width="1"
                stroke-dasharray="3 3"
                data-testid="today-line"
            />

            <!-- 横軸の見出し -->
            <g class="text-[11px]">
                <template v-for="label in xLabels" :key="`label-${label.key}`">
                    <template v-if="label.show">
                        <text :x="xCenter(label.index)" :y="HEIGHT - MARGIN.bottom + 16" text-anchor="middle" class="fill-stone-500 tabular-nums">{{ label.label }}</text>
                        <text v-if="label.sub" :x="xCenter(label.index)" :y="HEIGHT - MARGIN.bottom + 30" text-anchor="middle" class="fill-stone-400 text-[10px] tabular-nums">{{ label.sub }}</text>
                    </template>
                </template>
            </g>

            <!-- マウスを乗せる場所（棒より広く、月・日の帯の高さいっぱい） -->
            <rect
                v-for="(column, index) in columns"
                :key="`hit-${column.key}`"
                :x="xLeft(index)"
                :y="MARGIN.top"
                :width="band"
                :height="plotHeight"
                fill="transparent"
                tabindex="0"
                class="cursor-crosshair focus:outline-none"
                :aria-label="labels[index]?.title"
                data-testid="chart-band"
                @mouseenter="hovered = index"
                @focus="hovered = index"
                @blur="hovered = null"
            />
        </svg>

        <div
            v-if="tooltip"
            ref="tooltipElement"
            role="tooltip"
            class="pointer-events-none absolute z-10 w-64 max-w-full rounded-lg bg-stone-900 px-3 py-2 text-xs text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltipPosition.left}px`, top: `${tooltipPosition.top}px` }"
            data-testid="chart-tooltip"
        >
            <p class="mb-1 font-semibold text-white">{{ tooltip.title }}</p>
            <p v-for="(row, index) in tooltip.rows" :key="index" class="flex justify-between gap-4 tabular-nums">
                <span class="text-stone-400">{{ row.label }}</span>
                <span :class="row.tone === 'warning' ? 'font-semibold text-amber-300' : row.tone === 'muted' ? 'text-stone-300' : 'text-white'">{{ row.value }}</span>
            </p>
        </div>
    </div>
</template>
