<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from "vue";
import type { ActualColumn, ChartSeries } from "@/components/actuals/actualRows";

/**
 * 販売実績のグラフ（B-127、K-079）。kind が bar なら積み上げ棒グラフ（品番別売上の BOSS・Amazon）、line なら系列ごとの折れ線
 * （SKU別売上の BOSS・Amazon の2本）。グラフの作り方の指針に沿って、棒は24px以下で上端だけ4pxの角丸、積み上げの間に2pxの白い隙間、
 * 線は2pxで角を丸め、目盛り線は1pxの薄い実線とする。縦軸はきりのよい数の目盛り（棒は積み上げた合計、折れ線は一番大きい系列に合わせる）。
 * 月・日の帯にマウスを乗せる（またはフォーカスする）と、縦の線と点で示し、その月・日の合計と内訳をツールチップで出す。系列が2つ以上なら凡例を出す。
 * 販売実績を取り込んでいない月・日は棒も点も描かず（折れ線は前後の点を点線でつなぐ）、見出しを薄くしてツールチップでそのことを示す（原則2）。
 */

const props = withDefaults(
    defineProps<{
        columns: ActualColumn[];
        series: ChartSeries[];
        /** ツールチップの値の表示 */
        formatValue: (value: number) => string;
        /** 縦軸の目盛りの表示 */
        formatAxis: (value: number) => string;
        /** 縦軸の単位（「個」「円」） */
        axisUnit: string;
        kind?: "bar" | "line";
        height?: number;
    }>(),
    { kind: "bar", height: 300 },
);

const MARGIN = { top: 14, right: 12, bottom: 40, left: 64 };
const MAX_BAR = 24;
const GAP = 2;
const RADIUS = 4;

// グラフの幅は置き場所の幅に合わせる（ResizeObserver の無い環境では既定の幅）
const root = useTemplateRef<HTMLDivElement>("root");
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

const plotWidth = computed(() => width.value - MARGIN.left - MARGIN.right);
const plotHeight = computed(() => props.height - MARGIN.top - MARGIN.bottom);
const band = computed(() => plotWidth.value / Math.max(1, props.columns.length));
const barWidth = computed(() => Math.max(2, Math.min(MAX_BAR, band.value * 0.68)));

/** 列ごとの合計（表示している系列）。データの無い列は null。 */
const totals = computed(() =>
    props.columns.map((_, index) => {
        const values = props.series.map((series) => series.values[index] ?? null);
        return values.every((value) => value === null) ? null : values.reduce<number>((total, value) => total + Math.max(0, value ?? 0), 0);
    }),
);

/** きりのよい目盛り（0から、4～5本）。 */
const ticks = computed(() => {
    const peaks = props.kind === "line" ? props.series.flatMap((series) => series.values) : totals.value;
    const max = Math.max(0, ...peaks.filter((value): value is number => value !== null));
    if (max <= 0) {
        return [0, 1];
    }
    const rough = max / 4;
    const power = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((factor) => factor * power).find((candidate) => candidate >= rough)!;
    const count = Math.ceil(max / step);
    return Array.from({ length: count + 1 }, (_, index) => index * step);
});
const yMax = computed(() => ticks.value.at(-1)!);
const y = (value: number): number => MARGIN.top + plotHeight.value - (value / yMax.value) * plotHeight.value;
const xCenter = (index: number): number => MARGIN.left + band.value * (index + 0.5);

/** 棒の部分（下から積む）。一番上の部分だけ上端を角丸にし、部分の間は白い隙間で分ける。 */
const stacks = computed(() =>
    props.columns.map((_, index) => {
        const segments: { key: string; color: string; path: string }[] = [];
        let base = 0;
        const parts = props.series.map((series) => ({ series, value: Math.max(0, series.values[index] ?? 0) })).filter((part) => part.value > 0);
        parts.forEach((part, partIndex) => {
            const top = base + part.value;
            const x0 = xCenter(index) - barWidth.value / 2;
            const x1 = x0 + barWidth.value;
            const yBottom = y(base) - (partIndex > 0 ? GAP : 0);
            const yTop = y(top);
            base = top;
            if (yBottom - yTop < 0.5) {
                return;
            }
            const isTop = partIndex === parts.length - 1;
            const r = isTop ? Math.min(RADIUS, (yBottom - yTop) / 2, barWidth.value / 2) : 0;
            const path = isTop
                ? `M ${x0} ${yBottom} L ${x0} ${yTop + r} Q ${x0} ${yTop} ${x0 + r} ${yTop} L ${x1 - r} ${yTop} Q ${x1} ${yTop} ${x1} ${yTop + r} L ${x1} ${yBottom} Z`
                : `M ${x0} ${yBottom} L ${x0} ${yTop} L ${x1} ${yTop} L ${x1} ${yBottom} Z`;
            segments.push({ key: part.series.key, color: part.series.color, path });
        });
        return segments;
    }),
);

/**
 * 折れ線（系列ごと）。続いている点は実線でつなぎ、販売実績を取り込んでいない列（点を打たない）を挟む前後の点は点線でつなぐ（K-079）。
 */
const lines = computed(() =>
    props.kind !== "line"
        ? []
        : props.series.map((series) => {
              const points = series.values.flatMap((value, index) => (value === null ? [] : [{ index, x: xCenter(index), y: y(value) }]));
              const solid: string[] = [];
              const dashed: string[] = [];
              points.slice(1).forEach((point, position) => {
                  const previous = points[position]!;
                  const segment = `M ${previous.x.toFixed(1)} ${previous.y.toFixed(1)} L ${point.x.toFixed(1)} ${point.y.toFixed(1)}`;
                  (point.index - previous.index === 1 ? solid : dashed).push(segment);
              });
              return { key: series.key, color: series.color, solid: solid.join(" "), dashed: dashed.join(" "), points };
          }),
);

/** 折れ線の点をいつも出すか（日ごとのように列が多いときは、マウスを乗せた列だけ）。 */
const showPoints = computed(() => props.columns.length <= 31);

/** 横軸の見出し。重ならないよう間引き、最後の列は必ず出す。 */
const xLabels = computed(() => {
    const every = Math.max(1, Math.ceil(46 / band.value));
    const last = props.columns.length - 1;
    return props.columns.map((column, index) => ({ column, index, show: index === last || (index % every === 0 && last - index >= every) }));
});

// ツールチップ
const hovered = ref<number | null>(null);
const tooltip = computed(() => {
    if (hovered.value === null) {
        return null;
    }
    const index = hovered.value;
    const column = props.columns[index]!;
    const total = totals.value[index] ?? null;
    // 折れ線は0の系列も出す（2本のどちらが売れていないかも分かるように）
    const rows = props.series
        .map((series) => ({ key: series.key, label: series.label, color: series.color, value: series.values[index] ?? null }))
        .filter((row) => row.value !== null && (props.kind === "line" || row.value > 0))
        .sort((a, b) => b.value! - a.value!);
    const left = Math.min(Math.max(xCenter(index), 110), width.value - 110);
    return { column, total, rows, left };
});
</script>

<template>
    <div ref="root" class="relative flex flex-col gap-3" data-testid="sales-chart">
        <div v-if="series.length >= 2" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-stone-600" data-testid="chart-legend">
            <span v-for="item in series" :key="item.key" class="inline-flex items-center gap-1.5" :data-testid="`legend-${item.key}`">
                <span :class="kind === 'line' ? 'h-0.5 w-3.5 rounded-full' : 'h-2.5 w-2.5 rounded-sm'" :style="{ backgroundColor: item.color }" />
                {{ item.label }}
            </span>
        </div>

        <svg :width="width" :height="height" class="block max-w-full" role="img" :aria-label="`${axisUnit}の推移`" @mouseleave="hovered = null">
            <!-- 目盛り線と縦軸 -->
            <g class="text-[11px]">
                <template v-for="tick in ticks" :key="tick">
                    <line :x1="MARGIN.left" :x2="width - MARGIN.right" :y1="y(tick)" :y2="y(tick)" :class="tick === 0 ? 'stroke-stone-300' : 'stroke-stone-200'" stroke-width="1" />
                    <text :x="MARGIN.left - 8" :y="y(tick)" text-anchor="end" dominant-baseline="middle" class="fill-stone-500 tabular-nums">{{ formatAxis(tick) }}</text>
                </template>
                <text :x="MARGIN.left - 8" :y="MARGIN.top - 4" text-anchor="end" class="fill-stone-400 text-[10px]">{{ axisUnit }}</text>
            </g>

            <!-- 帯（マウスを乗せた月・日を薄く示す） -->
            <rect v-if="hovered !== null" :x="MARGIN.left + band * hovered" :y="MARGIN.top" :width="band" :height="plotHeight" class="fill-stone-100" />

            <!-- 棒 -->
            <template v-if="kind === 'bar'">
                <g v-for="(segments, index) in stacks" :key="columns[index]!.key">
                    <path v-for="segment in segments" :key="segment.key" :d="segment.path" :fill="segment.color" data-testid="chart-segment" :data-series="segment.key" />
                </g>
            </template>

            <!-- 折れ線（マウスを乗せた列には縦の線） -->
            <template v-else>
                <line v-if="hovered !== null" :x1="xCenter(hovered)" :x2="xCenter(hovered)" :y1="MARGIN.top" :y2="MARGIN.top + plotHeight" class="stroke-stone-300" stroke-width="1" />
                <g v-for="line in lines" :key="line.key" :data-series="line.key" data-testid="chart-line">
                    <path v-if="line.solid" :d="line.solid" fill="none" :stroke="line.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" data-testid="line-solid" />
                    <path v-if="line.dashed" :d="line.dashed" fill="none" :stroke="line.color" stroke-width="2" stroke-dasharray="4 4" stroke-linecap="round" data-testid="line-dashed" />
                    <template v-for="point in line.points" :key="point.index">
                        <circle
                            v-if="showPoints || point.index === hovered"
                            :cx="point.x"
                            :cy="point.y"
                            :r="point.index === hovered ? 4 : 2.5"
                            :fill="line.color"
                            class="stroke-white"
                            :stroke-width="point.index === hovered ? 2 : 0"
                            data-testid="chart-point"
                        />
                    </template>
                </g>
            </template>

            <!-- 横軸の見出し -->
            <g class="text-[11px]">
                <template v-for="label in xLabels" :key="label.column.key">
                    <text
                        v-if="label.show"
                        :x="xCenter(label.index)"
                        :y="height - MARGIN.bottom + 16"
                        text-anchor="middle"
                        class="tabular-nums"
                        :class="label.column.muted ? 'fill-stone-300' : 'fill-stone-500'"
                    >
                        {{ label.column.label }}
                    </text>
                </template>
            </g>

            <!-- マウスを乗せる場所（棒より広く、月・日の帯の高さいっぱい） -->
            <rect
                v-for="(column, index) in columns"
                :key="`hit-${column.key}`"
                :x="MARGIN.left + band * index"
                :y="MARGIN.top"
                :width="band"
                :height="plotHeight"
                fill="transparent"
                tabindex="0"
                class="cursor-crosshair focus:outline-none"
                :aria-label="column.label"
                data-testid="chart-band"
                @mouseenter="hovered = index"
                @focus="hovered = index"
                @blur="hovered = null"
            />
        </svg>

        <div
            v-if="tooltip"
            role="tooltip"
            class="pointer-events-none absolute z-10 w-52 -translate-x-1/2 rounded-lg bg-stone-900 px-3 py-2 text-xs text-stone-100 shadow-xl ring-1 ring-black/20"
            :style="{ left: `${tooltip.left}px`, top: `${series.length >= 2 ? 36 : 8}px` }"
            data-testid="chart-tooltip"
        >
            <div class="flex items-baseline justify-between gap-2 border-b border-white/10 pb-1">
                <span class="font-semibold text-white">{{ tooltip.column.label }}</span>
                <span class="text-[10px] text-stone-400">{{ tooltip.column.sub }}</span>
            </div>
            <template v-if="tooltip.total === null">
                <p class="pt-1 text-stone-300">販売実績を取り込んでいません</p>
            </template>
            <template v-else>
                <div class="flex justify-between pt-1 tabular-nums">
                    <span class="text-stone-300">合計</span><span class="font-semibold text-white" data-testid="tooltip-total">{{ formatValue(tooltip.total) }}</span>
                </div>
                <div v-for="row in tooltip.rows" :key="row.key" class="flex items-center justify-between gap-2 tabular-nums">
                    <span class="flex min-w-0 items-center gap-1.5 text-stone-300"
                        ><span class="h-2 w-2 shrink-0 rounded-sm" :style="{ backgroundColor: row.color }" /><span class="truncate">{{ row.label }}</span></span
                    >
                    <span>{{ formatValue(row.value!) }}</span>
                </div>
            </template>
        </div>
    </div>
</template>
