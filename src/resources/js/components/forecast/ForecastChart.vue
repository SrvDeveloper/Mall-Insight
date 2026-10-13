<script setup lang="ts">
import { computed, ref } from "vue";
import { MALL_SERIES } from "@/components/actuals/actualRows";
import { CHANNELS, type ForecastChannel, type TimelineFigures, type TimelineLabel } from "@/components/forecast/timelineRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面（B-131、K-123）のグラフ。画面デザイン案（需要予測 画面デザイン案）のとおりに描く。
 * 月・日ごとに、過去は販売実績、今月（今日）から先は需要予測を、モールごとの積み上げ棒で出す。需要予測は斜線で、今月は前日までの
 * 販売実績の上に残りの予測を積む（今月の予測は1か月分、K-052）。過去の月・日には予測し直した数を横線で重ね、今日の位置に点線を引く。
 * 日ごとは、上にイベントの期間の帯を出し、土日と欠品した日の地を変え、見出しは今日と毎週月曜（今日の前後3日の月曜は出さない）に出す。
 * 月・日にマウスを乗せる（またはフォーカスする）と、その月・日の内訳をグラフの上に出す。
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
    /** グラフの高さ（px、目盛りの欄を除く） */
    height?: number;
    /** 縦軸の目盛りの欄の幅（px） */
    axisWidth?: number;
}>();

const AXIS = props.axisWidth ?? 56;
const isDay = computed(() => props.unit === "day");
const plotHeight = computed(() => props.height ?? 240);
const gridColumns = computed(() => ({ gridTemplateColumns: `${AXIS}px repeat(${props.columns.length}, minmax(0, 1fr))` }));
const colorOf = (channel: ForecastChannel): string => MALL_SERIES.find((mall) => mall.key === channel)!.color;
const labelOf = (channel: ForecastChannel): string => MALL_SERIES.find((mall) => mall.key === channel)!.label;

/** 列ごとの、チャネルの販売実績と、斜線で積む需要予測（今月は残りの分）。 */
const raw = computed(() =>
    props.columns.map((column, index) => {
        const values = { boss: 0, amazon: 0, bossFc: 0, amazonFc: 0 };
        for (const channel of CHANNELS) {
            const series = props.figures.channels[channel];
            if (!series) {
                continue;
            }
            const actual = column.kind === "future" ? 0 : (series.actual[index] ?? 0);
            const forecast = series.forecast[index] ?? 0;
            values[channel] = actual;
            values[`${channel}Fc`] = column.kind === "past" ? 0 : Math.max(0, forecast - actual);
        }
        return values;
    }),
);

/** 縦軸：きりのよい刻みの4つ分を上端にする。 */
const scale = computed(() => {
    const peak = Math.max(
        0,
        ...raw.value.map((values, index) => Math.max(values.boss + values.amazon + values.bossFc + values.amazonFc, props.columns[index]!.kind === "past" ? (props.figures.replayed[index] ?? 0) : 0)),
    );
    if (peak <= 0) {
        return { step: 1, top: 4 };
    }
    const magnitude = 10 ** Math.floor(Math.log10(peak / 4));
    const step = [1, 2, 2.5, 5, 10].map((factor) => factor * magnitude).find((candidate) => candidate * 4 >= peak) ?? magnitude * 10;
    return { step, top: step * 4 };
});
const px = (value: number): number => Math.max(0, (value / scale.value.top) * plotHeight.value);
const axis = computed(() => [0, 1, 2, 3, 4].map((index) => ({ value: scale.value.step * index, bottom: px(scale.value.step * index) })));

const hovered = ref<number | null>(null);

const segmentStyle = (height: number, color?: string): Record<string, string> => ({
    display: "block",
    width: isDay.value ? "72%" : "58%",
    flexShrink: "0",
    borderRadius: "2px",
    height: `${height.toFixed(1)}px`,
    marginTop: `${height > 0 ? (isDay.value ? 1 : 2) : 0}px`,
    ...(color ? { background: color } : {}),
});

const cells = computed(() =>
    props.columns.map((column, index) => {
        const values = raw.value[index]!;
        const replayed = props.figures.replayed[index];
        let background = "";
        if (isDay.value && props.figures.stockout[index] && column.kind === "past") {
            background = "#fef3c7";
        } else if (props.labels[index]?.weekend) {
            background = "#fafaf9";
        }
        if (hovered.value === index) {
            background = isDay.value ? "rgba(28,25,23,.06)" : "rgba(28,25,23,.04)";
        }
        return {
            key: column.key,
            style: {
                height: `${plotHeight.value}px`,
                ...(column.kind === "current" ? { borderLeft: "1px dashed #a8a29e" } : {}),
                ...(background ? { background } : {}),
            },
            isCurrent: column.kind === "current",
            amazonFc: segmentStyle(px(values.amazonFc)),
            bossFc: segmentStyle(px(values.bossFc)),
            amazon: segmentStyle(px(values.amazon), colorOf("amazon")),
            boss: segmentStyle(px(values.boss), colorOf("boss")),
            tick:
                column.kind === "past" && replayed !== null && replayed !== undefined
                    ? {
                          left: isDay.value ? "6%" : "14%",
                          width: isDay.value ? "88%" : "72%",
                          height: `${isDay.value ? 2 : 3}px`,
                          bottom: `${(px(replayed) - 1).toFixed(1)}px`,
                      }
                    : null,
        };
    }),
);

/** 横軸の見出し。日ごとは今日と毎週月曜（今日の前後3日の月曜は今日の日付と重なるため出さない）。 */
const xLabels = computed(() => {
    const today = props.columns.findIndex((column) => column.kind === "current");
    return props.columns.map((column, index) => {
        const label = props.labels[index]!;
        if (!isDay.value) {
            return { key: column.key, label: label.label, year: label.sub };
        }
        const show = index === today || (label.sub === "月" && (today < 0 || Math.abs(index - today) >= 4));
        return { key: column.key, label: show ? label.label : "", year: "" };
    });
});

/** 日ごとの、イベントの期間の帯（続いた日をまとめる）。 */
const bands = computed(() => {
    if (!isDay.value) {
        return [];
    }
    const result: { key: string; name: string; channel: ForecastChannel; first: number; last: number }[] = [];
    props.events.forEach((events, index) => {
        for (const event of events) {
            const current = result.find((band) => band.key === String(event.id) && band.last === index - 1);
            if (current) {
                current.last = index;
            } else {
                result.push({ key: String(event.id), name: event.name, channel: event.channels[0] ?? "boss", first: index, last: index });
            }
        }
    });
    // 重なる期間は段を分ける（空いている一番上の段に置く）
    const rows: { first: number; last: number }[][] = [];
    const rowOf = result.map((band) => {
        let row = rows.findIndex((placed) => placed.every((other) => other.last < band.first || other.first > band.last));
        if (row < 0) {
            row = rows.length;
            rows.push([]);
        }
        rows[row]!.push(band);
        return row;
    });
    return result.map((band, position) => {
        const first = props.columns[band.first]!.key;
        const last = props.columns[band.last]!.key;
        return {
            ...band,
            title: `${band.name}（${labelOf(band.channel)}）${Number(first.slice(5, 7))}/${Number(first.slice(8, 10))}〜${Number(last.slice(5, 7))}/${Number(last.slice(8, 10))}`,
            style: { gridRow: String(rowOf[position]! + 1), gridColumn: `${band.first + 2} / ${band.last + 3}` },
            dot: { background: colorOf(band.channel) },
        };
    });
});

const tooltip = computed(() => {
    if (hovered.value === null) {
        return null;
    }
    const index = hovered.value;
    const column = props.columns[index]!;
    const rows: { label: string; value: string; tone: "normal" | "warning" | "stockout" }[] = [];
    for (const channel of CHANNELS) {
        const series = props.figures.channels[channel];
        if (!series) {
            continue;
        }
        const actual = series.actual[index];
        if (column.kind === "past") {
            rows.push({ label: `${labelOf(channel)} の実績`, value: actual === null || actual === undefined ? "—" : props.formatValue(actual), tone: "normal" });
        } else {
            if (column.kind === "current" && !isDay.value && channel === CHANNELS.find((item) => props.figures.channels[item])) {
                rows.push({ label: "前日までの実績", value: props.formatValue(props.figures.actual[index] ?? 0), tone: "normal" });
            }
            const forecast = series.forecast[index];
            rows.push({ label: `${labelOf(channel)} の予測`, value: forecast === null || forecast === undefined ? "予測できません" : props.formatValue(forecast), tone: "normal" });
        }
    }
    if (column.kind === "past") {
        const replayed = props.figures.replayed[index];
        const actual = props.figures.actual[index];
        rows.push({ label: "予測し直した数", value: replayed === null || replayed === undefined ? "予測できません" : props.formatValue(replayed), tone: "normal" });
        const variance = props.figures.variance[index];
        const stockouts = props.figures.stockoutCount[index] ?? 0;
        if (isDay.value && stockouts > 0) {
            rows.push({ label: "欠品したSKU", value: `${stockouts}件（差異に数えない）`, tone: "stockout" });
        }
        if (isDay.value && props.figures.stockout[index]) {
            rows.push({ label: "差異", value: "欠品の日は数えない", tone: "stockout" });
        } else if (variance !== null && variance !== undefined && replayed !== null && replayed !== undefined && actual !== null && actual !== undefined) {
            const difference = replayed - actual;
            rows.push({
                label: "差異",
                value: `${difference >= 0 ? "+" : "−"}${props.formatValue(Math.abs(difference))}（${variance >= 0 ? "+" : "−"}${Math.abs(variance * 100).toFixed(1)}%）`,
                tone: Math.abs(variance) >= props.threshold ? "warning" : "normal",
            });
        }
    }
    for (const event of props.events[index] ?? []) {
        rows.push({ label: "イベント", value: `${event.name}（${event.channels.map((channel) => labelOf(channel)).join("・")}）`, tone: "normal" });
    }
    const count = props.columns.length;
    const shift = index < count / 6 ? "-12%" : index > (count * 5) / 6 ? "-88%" : "-50%";
    const suffix = column.kind === "past" ? "" : column.kind === "current" ? (isDay.value ? "（今日）" : "（今月）") : "（予測）";
    return {
        title: `${props.labels[index]!.title}${suffix}`,
        rows,
        style: { left: `calc(${AXIS}px + (100% - ${AXIS}px) * ${((index + 0.5) / count).toFixed(4)})`, transform: `translate(${shift}, -100%)` },
    };
});
</script>

<template>
    <div class="relative pt-2" data-testid="forecast-chart">
        <div
            v-if="tooltip"
            role="tooltip"
            class="pointer-events-none absolute top-[-8px] z-[5] flex min-w-[220px] flex-col gap-[3px] rounded-[10px] border border-stone-200 bg-white/95 px-3 py-2.5 shadow-[0_8px_24px_rgba(28,25,23,0.12)]"
            :style="tooltip.style"
            data-testid="chart-tooltip"
        >
            <p class="mb-1.5 text-xs font-semibold text-stone-900">{{ tooltip.title }}</p>
            <p v-for="(row, index) in tooltip.rows" :key="index" class="flex justify-between gap-4 text-xs text-stone-600 tabular-nums">
                <span>{{ row.label }}</span>
                <strong :class="row.tone === 'warning' ? 'text-amber-700' : row.tone === 'stockout' ? 'text-amber-800' : 'text-stone-900'">{{ row.value }}</strong>
            </p>
        </div>

        <div v-if="isDay" class="mb-1.5 grid" :style="gridColumns" data-testid="event-bands">
            <span
                v-for="band in bands"
                :key="`${band.key}-${band.first}`"
                :title="band.title"
                class="mx-px flex h-5 items-center gap-1 overflow-hidden rounded-[5px] border border-stone-200 bg-white px-1.5 text-[10px] whitespace-nowrap text-stone-700"
                :style="band.style"
                data-testid="event-band"
                ><span aria-hidden="true" class="size-1.5 shrink-0 rounded-full" :style="band.dot" />{{ band.name }}</span
            >
        </div>

        <div class="relative grid" :style="gridColumns" @mouseleave="hovered = null">
            <div class="relative" :style="{ height: `${plotHeight}px` }">
                <span v-for="tick in axis" :key="tick.value" class="absolute right-2 text-[10px] text-stone-400 tabular-nums" :style="{ bottom: `${tick.bottom - 7}px` }" data-testid="chart-axis">{{
                    formatAxis(tick.value)
                }}</span>
            </div>
            <div
                v-for="(cell, index) in cells"
                :key="cell.key"
                class="relative z-[1] flex cursor-default flex-col items-center justify-end focus:outline-none"
                :style="cell.style"
                tabindex="0"
                :aria-label="labels[index]?.title"
                data-testid="chart-band"
                @mouseenter="hovered = index"
                @focus="hovered = index"
                @blur="hovered = null"
            >
                <span class="hatch-amazon" :style="cell.amazonFc" data-testid="chart-segment" data-segment="amazon-forecast" />
                <span class="hatch-boss" :style="cell.bossFc" data-testid="chart-segment" data-segment="boss-forecast" />
                <span :style="cell.amazon" data-testid="chart-segment" data-segment="amazon-actual" />
                <span :style="cell.boss" data-testid="chart-segment" data-segment="boss-actual" />
                <span v-if="cell.tick" class="absolute rounded-[2px] bg-stone-900 shadow-[0_0_0_1.5px_#fff]" :style="cell.tick" data-testid="replay-tick" />
                <span v-if="cell.isCurrent" class="sr-only" data-testid="today-line">今日</span>
            </div>
            <span
                v-for="tick in axis"
                :key="`line-${tick.value}`"
                class="pointer-events-none absolute right-0 h-px"
                :class="tick.value === 0 ? 'bg-stone-300' : 'bg-stone-100'"
                :style="{ bottom: `${tick.bottom}px`, left: `${AXIS}px` }"
            />
        </div>

        <div class="mt-1.5 grid text-[11px] text-stone-500" :class="isDay ? 'text-left' : 'text-center'" :style="gridColumns">
            <span />
            <span v-for="label in xLabels" :key="label.key" class="flex flex-col leading-[1.3] whitespace-nowrap" :class="isDay ? 'overflow-visible' : ''"
                ><span>{{ label.label }}</span
                ><span class="text-[10px] text-stone-400">{{ label.year }}</span></span
            >
        </div>
    </div>
</template>

<style scoped>
.hatch-boss {
    background: repeating-linear-gradient(135deg, rgba(42, 120, 214, 0.62) 0 3px, rgba(42, 120, 214, 0.2) 3px 6px);
}
.hatch-amazon {
    background: repeating-linear-gradient(135deg, rgba(235, 104, 52, 0.62) 0 3px, rgba(235, 104, 52, 0.2) 3px 6px);
}
</style>
