<script setup lang="ts">
import { computed } from "vue";
import type { SalesPeriod } from "@/api/actuals";
import type { SalesUnit } from "@/types/api";

/**
 * 品番別売上・SKU別売上の期間指定（K-080）。よく使う期間の引き出しと、開始〜終了の自由指定。
 * 月ごとは月（YYYY-MM）、日ごとは日付（YYYY-MM-DD）で選ぶ。月の引き出しは「今月と、その前の N か月」で、12か月が初期値（13か月）と同じ。
 * 受け付けられなかったとき（開始が終了より後など）は、その理由を出す。
 */

const props = defineProps<{ unit: SalesUnit; from: string; to: string; today: string; error: string | null }>();
const emit = defineEmits<{ change: [period: SalesPeriod] }>();

const pad = (value: number): string => String(value).padStart(2, "0");

/** YYYY-MM に月を足す。 */
function addMonths(month: string, count: number): string {
    const [year, mon] = month.split("-").map(Number) as [number, number];
    const index = year * 12 + (mon - 1) + count;
    return `${Math.floor(index / 12)}-${pad((index % 12) + 1)}`;
}

/** YYYY-MM-DD に日を足す（日本時間の暦日として扱う）。 */
function addDays(date: string, count: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + count);
    return value.toISOString().slice(0, 10);
}

const presets = computed<{ key: string; label: string; title: string; from: string; to: string }[]>(() => {
    if (props.unit === "day") {
        return [7, 30, 60, 90].map((days) => ({ key: `d${days}`, label: `直近${days}日`, title: `今日までの${days}日`, from: addDays(props.today, -(days - 1)), to: props.today }));
    }
    const current = props.today.slice(0, 7);
    const [year, month] = current.split("-").map(Number) as [number, number];
    const fiscalYear = month >= 4 ? year : year - 1;
    return [
        ...[3, 6, 12].map((months) => ({ key: `m${months}`, label: `直近${months}か月`, title: `今月と、その前の${months}か月`, from: addMonths(current, -months), to: current })),
        { key: "fy", label: "今年度", title: `${fiscalYear}年4月から今月まで`, from: `${fiscalYear}-04`, to: current },
        { key: "previous-fy", label: "前年度", title: `${fiscalYear - 1}年4月から${fiscalYear}年3月まで`, from: `${fiscalYear - 1}-04`, to: `${fiscalYear}-03` },
    ];
});

const inputType = computed(() => (props.unit === "day" ? "date" : "month"));
const latest = computed(() => (props.unit === "day" ? props.today : props.today.slice(0, 7)));

function select(from: string, to: string): void {
    if (from !== "" && to !== "") {
        emit("change", { from, to });
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="period-picker">
        <span class="text-xs font-medium text-stone-600">期間</span>
        <div class="inline-flex flex-wrap gap-1" role="group" aria-label="よく使う期間">
            <button
                v-for="preset in presets"
                :key="preset.key"
                type="button"
                class="h-8 rounded-full border bg-white px-3 text-[13px]"
                :class="from === preset.from && to === preset.to ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                :aria-pressed="from === preset.from && to === preset.to"
                :title="preset.title"
                :data-testid="`period-${preset.key}`"
                @click="select(preset.from, preset.to)"
            >
                {{ preset.label }}
            </button>
        </div>
        <div class="inline-flex items-center gap-1.5 text-[13px]">
            <label class="sr-only" for="period-from">開始</label>
            <input
                id="period-from"
                :type="inputType"
                :value="from"
                :max="latest"
                class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                data-testid="period-from"
                @change="select(($event.target as HTMLInputElement).value, to)"
            />
            <span class="text-stone-400">〜</span>
            <label class="sr-only" for="period-to">終了</label>
            <input
                id="period-to"
                :type="inputType"
                :value="to"
                :max="latest"
                class="h-8 rounded-lg border border-stone-200 bg-white px-2 text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                data-testid="period-to"
                @change="select(from, ($event.target as HTMLInputElement).value)"
            />
        </div>
        <span v-if="error" class="text-xs text-red-700" role="alert" data-testid="period-error">{{ error }}</span>
    </div>
</template>
