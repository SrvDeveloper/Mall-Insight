<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useTemplateRef } from "vue";
import type { ForecastTimelineResponse } from "@/types/api";

/**
 * 需要予測の画面と SKU別の需要予測（B-131、K-123）の、期間・計算日時の帯と、凡例の帯。画面デザイン案のとおりに描く。
 * 差異は、その月の初めに予測し直した数と比べる（起点は選ばない、K-135）。
 * 日ごとの期間は、欄を押すとよく使う期間と開始・終了を選ぶ欄を出す（過去は販売実績のある日まで、これからは90日先まで）。
 * 凡例は、グラフ（view: graph）と表（view: table）で出すものを変える。
 */

const props = withDefaults(defineProps<{ meta: ForecastTimelineResponse["meta"]; threshold: number; periodError: string | null; view?: "graph" | "table" }>(), { view: "graph" });
const emit = defineEmits<{ update: [changes: Record<string, string | undefined>] }>();

const isDay = computed(() => props.meta.unit === "day");

function addDays(date: string, count: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + count);
    return value.toISOString().slice(0, 10);
}
/** 「2026/9/13」「2025/10」（0を付けない）。 */
const slashDate = (value: string): string =>
    value
        .split("-")
        .map((part, index) => (index === 0 ? part : String(Number(part))))
        .join("/");

const today = computed(() => props.meta.forecast_from);
const maxTo = computed(() => addDays(today.value, props.meta.max_future_days - 1));
const salesFrom = computed(() =>
    props.meta.channels
        .map((channel) => channel.sales_data_from)
        .filter((date): date is string => date !== null)
        .sort()
        .at(0),
);
const currentPeriod = computed(() => ({ from: props.meta.columns[0]?.key ?? "", to: props.meta.columns.at(-1)?.key ?? "" }));
const pastCount = computed(() => props.meta.columns.filter((column) => column.kind === "past").length);
const futureCount = computed(() => props.meta.columns.filter((column) => column.kind === "future").length);
const periodText = computed(() =>
    isDay.value
        ? `${slashDate(currentPeriod.value.from)} 〜 ${slashDate(currentPeriod.value.to)}（過去${pastCount.value}日・これからの${futureCount.value}日）`
        : `${slashDate(currentPeriod.value.from)} 〜 ${slashDate(currentPeriod.value.to)}（過去${pastCount.value}か月・これからの${futureCount.value + 1}か月）`,
);
const presets = computed(() =>
    [
        { key: "d14", label: "前後2週間", from: -14, to: 14 },
        { key: "d30", label: "前後30日", from: -30, to: 30 },
        { key: "p90", label: "過去90日・30日先", from: -90, to: 30 },
        { key: "f90", label: "過去30日・90日先", from: -30, to: 89 },
    ].map((preset) => ({ ...preset, from: addDays(today.value, preset.from), to: addDays(today.value, preset.to) })),
);

const isOpen = ref(false);
const picker = useTemplateRef<HTMLDivElement>("picker");
function onDocumentClick(event: MouseEvent): void {
    if (picker.value && event.target instanceof window.Node && !picker.value.contains(event.target)) {
        close();
    }
}
function open(): void {
    if (!isDay.value || isOpen.value) {
        close();
        return;
    }
    isOpen.value = true;
    document.addEventListener("mousedown", onDocumentClick);
}
function close(): void {
    isOpen.value = false;
    document.removeEventListener("mousedown", onDocumentClick);
}
onBeforeUnmount(close);

function selectPeriod(from: string, to: string): void {
    if (from !== "" && to !== "") {
        emit("update", { from, to });
        close();
    }
}

const calculatedText = computed(() =>
    new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "numeric", day: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(props.meta.calculated_at)),
);
const salesToText = computed(() => {
    const dates = props.meta.channels.map((channel) => channel.sales_data_to).filter((date): date is string => date !== null);
    return dates.length === 0 ? "販売実績はまだありません" : `販売実績 ${slashDate(dates.sort().at(-1)!)} まで`;
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600">
        <div ref="picker" class="relative inline-flex items-center gap-2" data-testid="period-picker">
            期間
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-2.5 text-[13px] text-stone-900 tabular-nums"
                :class="isDay ? 'cursor-pointer hover:border-stone-300' : 'cursor-default'"
                :aria-expanded="isDay ? isOpen : undefined"
                :disabled="!isDay"
                data-testid="period-button"
                @click="open"
            >
                <span data-testid="period-text">{{ periodText }}</span>
                <svg v-if="isDay" aria-hidden="true" width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#78716c" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m4 6 4 4 4-4" />
                </svg>
            </button>
            <div
                v-if="isOpen"
                class="absolute top-full left-10 z-30 mt-1.5 flex w-max flex-col gap-3 rounded-[10px] border border-stone-200 bg-white p-3 shadow-[0_8px_24px_rgba(28,25,23,0.12)]"
                data-testid="period-panel"
            >
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="よく使う期間">
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="
                            currentPeriod.from === preset.from && currentPeriod.to === preset.to
                                ? 'border-stone-900 font-semibold text-stone-900'
                                : 'border-stone-200 text-stone-600 hover:border-stone-300'
                        "
                        :aria-pressed="currentPeriod.from === preset.from && currentPeriod.to === preset.to"
                        :data-testid="`period-${preset.key}`"
                        @click="selectPeriod(preset.from, preset.to)"
                    >
                        {{ preset.label }}
                    </button>
                </div>
                <div class="flex items-center gap-1.5 text-[13px]">
                    <label class="sr-only" for="forecast-from">開始</label>
                    <input
                        id="forecast-from"
                        type="date"
                        :value="currentPeriod.from"
                        :min="salesFrom"
                        :max="maxTo"
                        class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                        data-testid="period-from"
                        @change="selectPeriod(($event.target as HTMLInputElement).value, currentPeriod.to)"
                    />
                    <span class="text-stone-400">〜</span>
                    <label class="sr-only" for="forecast-to">終了</label>
                    <input
                        id="forecast-to"
                        type="date"
                        :value="currentPeriod.to"
                        :min="salesFrom"
                        :max="maxTo"
                        class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                        data-testid="period-to"
                        @change="selectPeriod(currentPeriod.from, ($event.target as HTMLInputElement).value)"
                    />
                </div>
                <p class="text-[11px] text-stone-500">過去は販売実績のある日まで、これからは{{ meta.max_future_days }}日先まで選べます。</p>
            </div>
            <span v-if="periodError" class="text-xs text-red-700" role="alert" data-testid="period-error">{{ periodError }}</span>
        </div>
        <span class="text-stone-500 tabular-nums" data-testid="calculated-at">計算 {{ calculatedText }}・{{ salesToText }}</span>
    </div>

    <div v-if="view === 'graph'" class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-[3px] bg-[#2a78d6]" />BOSS の実績</span>
        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-[3px] bg-[#eb6834]" />Amazon の実績</span>
        <span class="inline-flex items-center gap-1.5"
            ><span aria-hidden="true" class="hatch-boss size-3 rounded-[3px]" /><span aria-hidden="true" class="hatch-amazon -ml-1 size-3 rounded-[3px]" />需要予測（斜線）</span
        >
        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-[3px] w-3.5 rounded-sm bg-stone-900" />予測し直した数（過去）</span>
        <span class="inline-flex items-center gap-1.5" data-testid="legend-stockout"
            ><span aria-hidden="true" class="h-0.5 w-3.5 rounded-sm bg-amber-600" />欠品率（下の段。欠品した日数 ÷ 在庫の記録がある日数、SKUごと）</span
        >
        <template v-if="isDay">
            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-[3px] border border-amber-500 bg-amber-100" />欠品の日（差異に数えない）</span>
            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-[3px] bg-stone-100" />土日</span>
        </template>
        <span class="text-stone-400">差異＝（予測し直した数 − 実績）÷ 実績。差異が{{ threshold * 100 }}%以上の{{ isDay ? "日" : "月" }}を強調します</span>
    </div>
    <div v-else class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="legend">
        <span class="inline-flex items-center gap-1.5"
            ><span aria-hidden="true" class="size-3 rounded-[3px] border border-amber-300 bg-amber-50" />差異が{{ threshold * 100 }}%以上の{{ isDay ? "日" : "月" }}</span
        >
        <span v-if="isDay" class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-[3px] border border-amber-500 bg-amber-100" />欠品の日（差異に数えない）</span>
        <span class="inline-flex items-center gap-1.5"><span class="rounded border border-stone-200 px-1 text-[10px]">●</span>イベントで増やした{{ isDay ? "日" : "月" }}</span>
        <span class="text-stone-400">{{
            isDay
                ? "今日の実績はまだ取り込まれていません。土日は灰色の地です"
                : `今月の実績は${slashDate(
                      meta.channels
                          .map((channel) => channel.sales_data_to ?? "")
                          .sort()
                          .at(-1) ?? "",
                  )}までの分です`
        }}</span>
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
