<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { cancelForecastAdjustment, fetchForecastAdjustments, saveForecastAdjustment } from "@/api/forecastAdjustments";
import type { ForecastAdjustment } from "@/types/api";

/**
 * 手動での予測補正（バックログ B-109、決定記録 K-132）。SKU別の需要予測の画面で、品番の Amazon・BOSS ごとに、始める月から終える月まで
 * システムの予測に倍率（%）を掛ける補正を登録・変更・取り消しする。品番のすべてのSKUに同じ倍率を掛ける。
 * 変えるときは、前の補正を取り消して新しい補正を登録する（前の補正も記録として残す）。取り消した・終えた補正は「これまでの補正」に出す。
 * 登録・変更・取り消しをしたら changed を出し、呼び出し側で需要予測を読み直す。
 */

const props = defineProps<{
    itemNo: string;
    /** 補正を入れられるチャネル（その品番を売っていて、販売実績を取り込んだチャネル） */
    channels: { channel: "boss" | "amazon"; label: string }[];
    /** 予測の開始日（YYYY-MM-DD）。この月から12か月を選べる */
    forecastFrom: string;
}>();
const emit = defineEmits<{ changed: [] }>();

const adjustments = ref<ForecastAdjustment[]>([]);
const limits = ref({ min: 0, max: 500 });
const isLoading = ref(false);

async function load(): Promise<void> {
    isLoading.value = true;
    try {
        const response = await fetchForecastAdjustments(props.itemNo);
        adjustments.value = response.data;
        limits.value = { min: response.meta.min_percent, max: response.meta.max_percent };
    } catch {
        // 通信エラーは全体通知に表示される
    } finally {
        isLoading.value = false;
    }
}
watch(() => props.itemNo, load, { immediate: true });

const current = computed(() =>
    adjustments.value
        .filter((adjustment) => adjustment.status === "active" || adjustment.status === "scheduled")
        .sort((a, b) => a.channel.localeCompare(b.channel) || a.starts_on.localeCompare(b.starts_on)),
);
const history = computed(() => adjustments.value.filter((adjustment) => adjustment.status === "ended" || adjustment.status === "cancelled"));
const showHistory = ref(false);

/** 選べる月（予測の12か月）。 */
const months = computed(() => {
    const [year, month] = props.forecastFrom.split("-").map(Number) as [number, number];
    return Array.from({ length: 12 }, (_, index) => {
        const date = new Date(Date.UTC(year, month - 1 + index, 1));
        const value = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}`;
        return { value, label: `${date.getUTCFullYear()}年${date.getUTCMonth() + 1}月` };
    });
});
const monthLabel = (value: string): string => `${value.slice(0, 4)}/${Number(value.slice(5, 7))}`;
const periodLabel = (adjustment: ForecastAdjustment): string => `${monthLabel(adjustment.starts_on)}〜${adjustment.ends_on === null ? "（取り消すまで）" : monthLabel(adjustment.ends_on)}`;
const dateLabel = (value: string | null): string => (value === null ? "" : `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`);
/** これまでの補正の説明（「・BOSS 2026/11〜2027/1 120%・理由（担当者 10/13 登録、担当者 10/14 取り消し）」）。 */
const historyText = (adjustment: ForecastAdjustment): string =>
    `・${adjustment.channel_label} ${periodLabel(adjustment)} ${adjustment.rate_percent}%・${adjustment.reason}（${adjustment.created_by ?? "—"} ${dateLabel(adjustment.created_at)} 登録${
        adjustment.cancelled_at ? `、${adjustment.cancelled_by ?? "—"} ${dateLabel(adjustment.cancelled_at)} 取り消し` : ""
    }）`;
const STATUS_LABELS: Record<ForecastAdjustment["status"], string> = { active: "掛けている", scheduled: "これから", ended: "終えた", cancelled: "取り消した" };

/** 入力中の補正（null なら閉じている）。replaces は変える補正。 */
const editing = ref<{ replaces: ForecastAdjustment | null } | null>(null);
const form = reactive({ channel: "amazon" as "boss" | "amazon", startsOn: "", endsOn: "", ratePercent: "" as number | string, reason: "" });
const errors = ref<Record<string, string[]>>({});
const message = ref<string | null>(null);
const isSaving = ref(false);

function open(replaces: ForecastAdjustment | null): void {
    editing.value = { replaces };
    errors.value = {};
    message.value = null;
    form.channel = replaces?.channel ?? props.channels[0]?.channel ?? "amazon";
    form.startsOn = replaces ? (replaces.starts_on < months.value[0]!.value ? months.value[0]!.value : replaces.starts_on) : months.value[0]!.value;
    form.endsOn = replaces?.ends_on ?? "";
    form.ratePercent = replaces?.rate_percent ?? "";
    form.reason = replaces?.reason ?? "";
}

const endMonths = computed(() => months.value.filter((month) => month.value >= form.startsOn));
watch(
    () => form.startsOn,
    (startsOn) => {
        if (form.endsOn !== "" && form.endsOn < startsOn) {
            form.endsOn = startsOn;
        }
    },
);

/** 入力中の倍率の説明（「予測を2割増やします」）。 */
const rateHint = computed(() => {
    const rate = Number(form.ratePercent);
    if (form.ratePercent === "" || !Number.isFinite(rate)) {
        return "100%より大きいと予測を増やし、小さいと減らします。0%で予測を0にします。";
    }
    if (rate === 100) {
        return "100%は補正しないのと同じです。";
    }
    if (rate === 0) {
        return "予測を0にします。";
    }
    return `予測を${rate > 100 ? `${Math.round((rate - 100) * 10) / 10}%増やします` : `${Math.round((100 - rate) * 10) / 10}%減らします`}（予測 × ${rate / 100}）。`;
});

const errorOf = (key: string): string | null => errors.value[key]?.[0] ?? null;

async function save(): Promise<void> {
    if (isSaving.value || editing.value === null) {
        return;
    }
    isSaving.value = true;
    errors.value = {};
    message.value = null;
    try {
        await saveForecastAdjustment({
            item_no: props.itemNo,
            channel: form.channel,
            starts_on: form.startsOn,
            ends_on: form.endsOn === "" ? null : form.endsOn,
            rate_percent: Number(form.ratePercent),
            reason: form.reason.trim(),
            replaces: editing.value.replaces?.id ?? null,
        });
        editing.value = null;
        await load();
        emit("changed");
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = error.errors;
            message.value = Object.keys(error.errors).length === 0 ? error.message : null;
        }
    } finally {
        isSaving.value = false;
    }
}

async function cancel(adjustment: ForecastAdjustment): Promise<void> {
    if (!window.confirm(`${adjustment.channel_label}の ${periodLabel(adjustment)} の補正（${adjustment.rate_percent}%）を取り消しますか？取り消した補正は記録として残ります。`)) {
        return;
    }
    message.value = null;
    try {
        await cancelForecastAdjustment(adjustment.id);
        if (editing.value?.replaces?.id === adjustment.id) {
            editing.value = null;
        }
        await load();
        emit("changed");
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            message.value = error.message;
        }
    }
}

const inputClass = "h-9 w-full rounded-lg border border-stone-300 bg-white px-2.5 text-[13px] text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
const labelClass = "text-[11px] font-medium text-stone-600";
</script>

<template>
    <section class="border-b border-stone-200" aria-labelledby="adjustment-heading" data-testid="adjustment-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-3.5 pb-2.5">
            <div class="flex min-w-0 flex-col gap-0.5">
                <h2 id="adjustment-heading" class="m-0 text-[13px] font-semibold text-stone-900">
                    予測の補正<span v-if="current.length > 0" class="ml-1.5 rounded-full bg-stone-900 px-1.5 py-px text-[11px] font-semibold text-white tabular-nums">{{ current.length }}</span>
                </h2>
                <p class="m-0 text-[11px] text-stone-500">
                    システムの予測では扱えない事情（広告の強化・販売の縮小など）を、品番のチャネルごとに倍率で入れます。品番のすべてのSKUに同じ倍率を掛けます。
                </p>
            </div>
            <button
                v-if="editing === null && channels.length > 0"
                type="button"
                class="inline-flex h-8 items-center gap-1 rounded-lg border border-stone-300 px-3 text-[13px] font-medium text-stone-800 hover:bg-stone-50"
                data-testid="adjustment-add"
                @click="open(null)"
            >
                <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9" /></svg>
                補正を入れる
            </button>
        </div>

        <p v-if="message" class="mx-4 mb-2.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="alert" data-testid="adjustment-message">{{ message }}</p>

        <form v-if="editing" class="mx-4 mb-3.5 flex flex-col gap-3 rounded-[10px] border border-stone-300 bg-stone-50 p-3.5" data-testid="adjustment-form" @submit.prevent="save">
            <p class="m-0 text-xs font-semibold text-stone-800">
                {{ editing.replaces ? `${editing.replaces.channel_label}の ${periodLabel(editing.replaces)} の補正を変える` : "補正を入れる" }}
                <span v-if="editing.replaces" class="ml-1 font-normal text-stone-500">（前の補正は取り消して記録に残します）</span>
            </p>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-[auto_repeat(3,minmax(0,1fr))]">
                <div class="col-span-2 flex flex-col gap-1 md:col-span-1">
                    <span :class="labelClass">チャネル</span>
                    <div class="inline-flex h-9 gap-0.5 rounded-lg bg-stone-200/70 p-[3px]" role="group" aria-label="チャネル">
                        <button
                            v-for="option in channels"
                            :key="option.channel"
                            type="button"
                            class="rounded-md px-3 text-[13px] disabled:cursor-not-allowed"
                            :class="form.channel === option.channel ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                            :aria-pressed="form.channel === option.channel"
                            :disabled="editing.replaces !== null && form.channel !== option.channel"
                            :data-testid="`adjustment-channel-${option.channel}`"
                            @click="form.channel = option.channel"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
                <label class="flex flex-col gap-1">
                    <span :class="labelClass">始める月</span>
                    <select v-model="form.startsOn" :class="inputClass" data-testid="adjustment-starts-on">
                        <option v-for="month in months" :key="month.value" :value="month.value">{{ month.label }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span :class="labelClass">終える月</span>
                    <select v-model="form.endsOn" :class="inputClass" data-testid="adjustment-ends-on">
                        <option value="">取り消すまで続ける</option>
                        <option v-for="month in endMonths" :key="month.value" :value="month.value">{{ month.label }}</option>
                    </select>
                </label>
                <label class="col-span-2 flex flex-col gap-1 md:col-span-1">
                    <span :class="labelClass">倍率</span>
                    <span class="relative">
                        <input
                            v-model="form.ratePercent"
                            type="number"
                            inputmode="numeric"
                            :min="limits.min"
                            :max="limits.max"
                            step="1"
                            placeholder="120"
                            :class="[inputClass, 'pr-8 text-right tabular-nums']"
                            data-testid="adjustment-rate"
                        />
                        <span class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-[13px] text-stone-500">%</span>
                    </span>
                </label>
            </div>
            <p class="-mt-1 m-0 text-[11px] text-stone-500" data-testid="adjustment-rate-hint">{{ rateHint }}</p>
            <label class="flex flex-col gap-1">
                <span :class="labelClass">理由</span>
                <input v-model="form.reason" type="text" maxlength="200" placeholder="例：11月からAmazonの広告を強めるため" :class="inputClass" data-testid="adjustment-reason" />
            </label>
            <ul v-if="Object.keys(errors).length > 0" class="m-0 flex list-none flex-col gap-0.5 p-0 text-xs text-red-700" role="alert" data-testid="adjustment-errors">
                <li v-for="key in ['channel', 'starts_on', 'ends_on', 'rate_percent', 'reason', 'replaces', 'item_no'].filter((name) => errorOf(name))" :key="key">{{ errorOf(key) }}</li>
            </ul>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="submit"
                    class="h-8 rounded-lg bg-stone-900 px-3.5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:opacity-50"
                    :disabled="isSaving"
                    data-testid="adjustment-save"
                >
                    {{ editing.replaces ? "変える" : "登録する" }}
                </button>
                <button type="button" class="h-8 rounded-lg px-3 text-[13px] text-stone-600 hover:bg-stone-200/60" @click="editing = null">やめる</button>
                <span class="text-[11px] text-stone-500">在庫推移・推奨発注数にも、補正した予測を使います。予測精度と予測し直した数には掛けません。</span>
            </div>
        </form>

        <div v-if="current.length === 0 && !editing" class="px-4 pb-3.5 text-xs text-stone-500" data-testid="adjustment-empty">{{ isLoading ? "読み込み中…" : "補正はありません。" }}</div>
        <div v-else-if="current.length > 0" class="overflow-x-auto px-4 pb-3.5">
            <table class="w-full min-w-[640px] border-collapse text-xs [&_td]:border-t [&_td]:border-stone-100 [&_td]:px-2 [&_td]:py-2 [&_th]:px-2 [&_th]:pb-1.5">
                <thead class="text-[11px] font-medium text-stone-500">
                    <tr>
                        <th scope="col" class="text-left">チャネル</th>
                        <th scope="col" class="text-left">期間</th>
                        <th scope="col" class="text-right">倍率</th>
                        <th scope="col" class="text-left">理由</th>
                        <th scope="col" class="text-left">登録</th>
                        <th scope="col"><span class="sr-only">操作</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="adjustment in current" :key="adjustment.id" data-testid="adjustment-row">
                        <td class="whitespace-nowrap">
                            <span class="font-medium text-stone-900">{{ adjustment.channel_label }}</span>
                            <span
                                class="ml-1.5 rounded px-1.5 py-px text-[10px] font-semibold"
                                :class="adjustment.status === 'active' ? 'bg-stone-900 text-white' : 'border border-stone-300 text-stone-600'"
                                data-testid="adjustment-status"
                                >{{ STATUS_LABELS[adjustment.status] }}</span
                            >
                        </td>
                        <td class="whitespace-nowrap text-stone-700 tabular-nums">{{ periodLabel(adjustment) }}</td>
                        <td class="text-right font-semibold whitespace-nowrap text-stone-900 tabular-nums" data-testid="adjustment-rate-cell">{{ adjustment.rate_percent }}%</td>
                        <td class="max-w-[320px] text-stone-700">{{ adjustment.reason }}</td>
                        <td class="whitespace-nowrap text-stone-500">{{ adjustment.created_by ?? "—" }}・{{ dateLabel(adjustment.created_at) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="rounded-md px-2 py-1 text-xs font-medium text-stone-700 hover:bg-stone-100" data-testid="adjustment-change" @click="open(adjustment)">
                                変える
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 text-xs font-medium text-stone-500 hover:bg-stone-100 hover:text-red-700"
                                data-testid="adjustment-cancel"
                                @click="cancel(adjustment)"
                            >
                                取り消す
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="history.length > 0" class="px-4 pb-3">
            <button
                type="button"
                class="text-[11px] text-stone-500 underline-offset-2 hover:text-stone-800 hover:underline"
                :aria-expanded="showHistory"
                data-testid="adjustment-history-toggle"
                @click="showHistory = !showHistory"
            >
                これまでの補正（取り消した・終えた補正 {{ history.length }}件）を{{ showHistory ? "閉じる" : "見る" }}
            </button>
            <ul v-if="showHistory" class="m-0 mt-1.5 flex list-none flex-col gap-1 p-0 text-[11px] text-stone-500" data-testid="adjustment-history">
                <li v-for="adjustment in history" :key="adjustment.id" class="tabular-nums">
                    <span class="font-medium text-stone-600">{{ STATUS_LABELS[adjustment.status] }}</span
                    ><span>{{ historyText(adjustment) }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>
