<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { saveSeasonalFactor } from "@/api/seasonalFactors";
import { MONTH_LABELS, SOURCE_LABELS, formatFactor, lowMonths, parseFactor } from "@/components/seasonalFactor/seasonalFormat";
import { formatDateTime } from "@/components/salesTarget/format";
import type { SeasonalFactorDetailResponse, SeasonalIndexSummary } from "@/types/api";

/**
 * 品番1つの季節係数の登録（B-106、K-113）。一覧の下に、別のセクションとして出す。
 *
 * - 12か月の入力欄は、登録した係数があればその値、無ければ販売実績から求めた係数（初期値）、それも無ければ1で埋める。
 * - 品番・カテゴリー・対象品番全体の実績から求めた係数を選んで入れ直せる。「すべて1」で季節による増減を無くした状態から直せる。
 * - 入れた係数を棒、実績から求めた係数を印で並べて見比べる。
 * - 年・月ごとの販売数を並べ、欠品していた可能性のある月（その年の中央値の2割未満）に印を付ける。
 *   在庫の記録が2026年9月16日からのため、それより前の欠品は実績から求めた係数に「売れない月」として入ってしまうため。
 * - 保存のたびに記録を残し、登録をやめることもできる。
 */

const props = defineProps<{ detail: SeasonalFactorDetailResponse }>();
const emit = defineEmits<{ saved: [detail: SeasonalFactorDetailResponse]; close: [] }>();

const data = computed(() => props.detail.data);
const meta = computed(() => props.detail.meta);

const inputs = ref<string[]>([]);
const note = ref("");
const isSaving = ref(false);
const saveError = ref<string | null>(null);
const savedNote = ref<string | null>(null);

/** 入力欄の初めの値。 */
const startingFactors = computed<number[]>(() => data.value.current?.factors ?? (data.value.initial?.is_usable ? data.value.initial.factors : null) ?? Array<number>(12).fill(1));

function reset(): void {
    inputs.value = startingFactors.value.map((factor) => factor.toFixed(2));
    note.value = "";
    saveError.value = null;
}

watch(() => props.detail, reset, { immediate: true });

const parsed = computed(() => inputs.value.map((input) => parseFactor(input, meta.value.min_factor, meta.value.max_factor)));
const invalidIndexes = computed(() => new Set(parsed.value.flatMap((value, index) => (value === null ? [index] : []))));
const average = computed(() => (invalidIndexes.value.size > 0 ? null : parsed.value.reduce<number>((total, value) => total + value!, 0) / 12));
const isDirty = computed(() => {
    const saved = data.value.current?.factors ?? null;
    return saved === null || parsed.value.some((value, index) => value !== saved[index]) || note.value.trim() !== "";
});

function fill(factors: number[]): void {
    inputs.value = factors.map((factor) => factor.toFixed(2));
}

async function save(factors: number[] | null): Promise<void> {
    if (isSaving.value || (factors !== null && invalidIndexes.value.size > 0)) {
        return;
    }
    isSaving.value = true;
    saveError.value = null;
    savedNote.value = null;
    try {
        const saved = await saveSeasonalFactor(data.value.item_no, factors, note.value.trim() || null);
        savedNote.value = factors === null ? "登録をやめました。需要予測では季節による増減を掛けません。" : "季節係数を保存しました。需要予測と在庫予測に反映されます。";
        emit("saved", saved);
    } catch (error) {
        saveError.value = error instanceof ApiError ? error.message : "季節係数を保存できませんでした。";
    } finally {
        isSaving.value = false;
    }
}

function stop(): void {
    if (window.confirm(`${data.value.item_no} の季節係数の登録をやめますか？需要予測では季節による増減を掛けなくなります。`)) {
        void save(null);
    }
}

/** 実績から求めた係数の説明（「品番の実績・月平均 67個・2年分」）。 */
function levelNote(level: SeasonalIndexSummary): string {
    if (!level.factors) {
        return "12か月分を求められません（前後6か月の販売実績がそろう月が足りない）";
    }
    const years = Math.min(...level.years);
    return `月平均 ${Math.round(level.monthly_quantity).toLocaleString()}個・${years === Math.max(...level.years) ? `${years}年分` : `${years}〜${Math.max(...level.years)}年分`}${level.is_usable ? "" : `（月平均${meta.value.min_monthly_quantity}個未満のため初期値にしません）`}`;
}

const levelLabel = (level: SeasonalIndexSummary): string => `${level.source ? SOURCE_LABELS[level.source] : ""}${level.source === "category" && level.source_label ? `（${level.source_label}）` : ""}`;

// 棒グラフ：入れた係数（棒）と実績から求めた係数（印）。縦軸は0から、2か入れた値の大きい方まで
const CHART = { width: 600, height: 160, top: 12, bottom: 22, left: 30, right: 8 };
const initialFactors = computed(() => data.value.initial?.factors ?? null);
const chartMax = computed(() => Math.max(2, ...parsed.value.map((value) => value ?? 0), ...(initialFactors.value ?? [])));
const plotHeight = CHART.height - CHART.top - CHART.bottom;
const slot = (CHART.width - CHART.left - CHART.right) / 12;
const y = (value: number): number => CHART.top + plotHeight * (1 - value / chartMax.value);
const ticks = computed(() => [0, 1, 2, ...(chartMax.value > 3 ? [Math.floor(chartMax.value)] : [])].filter((tick, index, all) => all.indexOf(tick) === index && tick <= chartMax.value));

const lowByYear = computed(() => data.value.sales.map((row) => lowMonths(row.months)));
const hasLowMonths = computed(() => lowByYear.value.some((months) => months.size > 0));
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="seasonal-editor-title" data-testid="seasonal-editor">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="seasonal-editor-title" class="font-mono text-base font-bold text-stone-900">{{ data.item_no }}</h2>
                    <span class="rounded-full border border-stone-200 px-2 py-px text-[11px] text-stone-600">{{ data.brand }}・{{ data.category }}</span>
                    <span v-if="data.current?.factors" class="rounded-full bg-stone-900 px-2 py-px text-[11px] font-semibold text-white">登録済み</span>
                    <span v-else class="rounded-full bg-stone-100 px-2 py-px text-[11px] text-stone-600">未登録（季節による増減を掛けていません）</span>
                </div>
                <p class="text-xs text-stone-500">普段の月を1として、その月が何倍売れるかを入れます。月どうしの比だけが予測に効くため、平均が1でなくてもかまいません。</p>
            </div>
            <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                aria-label="閉じる"
                data-testid="editor-close"
                @click="emit('close')"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="m5 5 10 10M15 5 5 15" />
                </svg>
            </button>
        </header>

        <div class="flex flex-col gap-4 px-4 py-4">
            <!-- 入力欄 -->
            <div class="grid grid-cols-4 gap-2 sm:grid-cols-6 xl:grid-cols-12">
                <label v-for="(label, index) in MONTH_LABELS" :key="label" class="flex flex-col gap-1">
                    <span class="text-[11px] font-medium text-stone-600">{{ label }}</span>
                    <input
                        v-model="inputs[index]"
                        type="text"
                        inputmode="decimal"
                        class="h-9 w-full rounded-md border bg-white px-2 text-right text-sm tabular-nums focus:outline-none"
                        :class="invalidIndexes.has(index) ? 'border-red-500 text-red-700 focus:border-red-600' : 'border-stone-300 focus:border-stone-900'"
                        :aria-invalid="invalidIndexes.has(index) || undefined"
                        :aria-label="`${label}の季節係数`"
                        data-testid="factor-input"
                    />
                    <span class="text-right text-[10px] text-stone-400 tabular-nums" :title="initialFactors ? '実績から求めた係数' : undefined">{{
                        initialFactors ? `実績 ${formatFactor(initialFactors[index] ?? null)}` : "&nbsp;"
                    }}</span>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-stone-600">実績から求めた係数を入れる：</span>
                <button
                    v-for="level in data.levels"
                    :key="level.source ?? ''"
                    type="button"
                    class="h-8 rounded-lg border border-stone-300 bg-white px-2.5 text-xs font-medium text-stone-900 hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!level.factors"
                    :title="levelNote(level)"
                    :data-testid="`fill-${level.source}`"
                    @click="level.factors && fill(level.factors)"
                >
                    {{ levelLabel(level) }}
                </button>
                <button type="button" class="h-8 rounded-lg px-2.5 text-xs text-stone-600 hover:bg-stone-100 hover:text-stone-900" data-testid="fill-ones" @click="fill(Array(12).fill(1))">
                    すべて1にする
                </button>
                <span class="ml-auto text-xs text-stone-500 tabular-nums" data-testid="factor-average">平均 {{ average === null ? "—" : average.toFixed(2) }}</span>
            </div>
            <ul class="flex flex-col gap-0.5 text-[11px] text-stone-500" data-testid="level-notes">
                <li v-for="level in data.levels" :key="level.source ?? ''">
                    <span class="font-medium text-stone-700">{{ levelLabel(level) }}</span
                    >：{{ levelNote(level) }}
                </li>
            </ul>

            <!-- 入れた係数（棒）と実績から求めた係数（印） -->
            <figure class="flex flex-col gap-1.5">
                <figcaption class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-stone-600">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-stone-700" aria-hidden="true" />入れた係数</span>
                    <span v-if="initialFactors" class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-3.5 bg-sky-600" aria-hidden="true" />実績から求めた係数</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3.5 border-t border-dashed border-stone-400" aria-hidden="true" />1（普段の月）</span>
                </figcaption>
                <svg :viewBox="`0 0 ${CHART.width} ${CHART.height}`" class="h-40 w-full max-w-3xl" role="img" :aria-label="`${data.item_no}の季節係数`" data-testid="factor-chart">
                    <g class="text-[10px]" fill="currentColor">
                        <g v-for="tick in ticks" :key="tick">
                            <line
                                :x1="CHART.left"
                                :x2="CHART.width - CHART.right"
                                :y1="y(tick)"
                                :y2="y(tick)"
                                :stroke="tick === 1 ? '#a8a29e' : '#e7e5e4'"
                                :stroke-dasharray="tick === 1 ? '4 3' : undefined"
                            />
                            <text :x="CHART.left - 6" :y="y(tick) + 3" text-anchor="end" class="fill-stone-400">{{ tick }}</text>
                        </g>
                        <g v-for="(label, index) in MONTH_LABELS" :key="label">
                            <title>{{ label }}：入れた係数 {{ formatFactor(parsed[index] ?? null) }}{{ initialFactors ? `・実績 ${formatFactor(initialFactors[index] ?? null)}` : "" }}</title>
                            <rect
                                v-if="parsed[index] !== null"
                                :x="CHART.left + slot * index + slot * 0.22"
                                :y="y(parsed[index]!)"
                                :width="slot * 0.56"
                                :height="Math.max(0, CHART.top + plotHeight - y(parsed[index]!))"
                                rx="3"
                                class="fill-stone-700"
                            />
                            <line
                                v-if="initialFactors"
                                :x1="CHART.left + slot * index + slot * 0.12"
                                :x2="CHART.left + slot * index + slot * 0.88"
                                :y1="y(initialFactors[index]!)"
                                :y2="y(initialFactors[index]!)"
                                stroke="#0284c7"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                            <text :x="CHART.left + slot * index + slot / 2" :y="CHART.height - 6" text-anchor="middle" class="fill-stone-500">{{ index + 1 }}</text>
                        </g>
                    </g>
                </svg>
            </figure>

            <!-- 年・月ごとの販売数 -->
            <div class="flex flex-col gap-1.5">
                <h3 class="text-xs font-medium text-stone-700">月ごとの販売数（全チャネル、イベントの日を含む）</h3>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] border-separate border-spacing-0 text-xs tabular-nums" data-testid="sales-table">
                        <thead class="text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:px-2 [&_th]:py-1.5 [&_th]:font-medium">
                            <tr>
                                <th scope="col" class="text-left">年</th>
                                <th v-for="label in MONTH_LABELS" :key="label" scope="col" class="text-right">{{ label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, rowIndex) in data.sales" :key="row.year" class="[&>*]:border-b [&>*]:border-stone-100 [&>*]:px-2 [&>*]:py-1.5">
                                <th scope="row" class="text-left font-medium text-stone-700">{{ row.year }}</th>
                                <td
                                    v-for="(value, index) in row.months"
                                    :key="index"
                                    class="text-right"
                                    :class="value === null ? 'text-stone-300' : lowByYear[rowIndex]!.has(index) ? 'bg-amber-50 font-semibold text-amber-800' : 'text-stone-700'"
                                    :title="lowByYear[rowIndex]!.has(index) ? 'この年のほかの月よりかなり少なく、欠品していた可能性があります' : undefined"
                                    :data-low="lowByYear[rowIndex]!.has(index) || undefined"
                                >
                                    {{ value === null ? "—" : value.toLocaleString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="hasLowMonths" class="text-[11px] text-amber-700" data-testid="low-note">
                    色を付けた月は、その年のほかの月よりかなり少なく、欠品していた可能性があります。在庫の記録が2026年9月16日からのため、実績から求めた係数ではこの月が「売れない月」として小さく出ます。
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="text-xs font-medium text-stone-700">メモ<span class="ml-1 font-normal text-stone-500">（直した理由など、任意）</span></span>
                <input
                    v-model="note"
                    type="text"
                    maxlength="500"
                    placeholder="例：2024年5月は欠品のため、5月は1にした"
                    class="h-9 rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-stone-900 focus:outline-none"
                    data-testid="factor-note"
                />
            </label>

            <div v-if="data.history.length > 0" class="flex flex-col gap-1">
                <h3 class="text-xs font-medium text-stone-700">登録の履歴</h3>
                <ul class="flex flex-col gap-0.5 text-[11px] text-stone-500 tabular-nums" data-testid="factor-history">
                    <li v-for="record in data.history" :key="record.id">
                        {{ record.changed_at ? formatDateTime(record.changed_at) : "" }} {{ record.changed_by ?? "" }}：
                        <template v-if="record.factors">{{ record.factors.map((factor) => factor.toFixed(2)).join("・") }}</template>
                        <template v-else>登録をやめた</template>
                        <template v-if="record.note">（{{ record.note }}）</template>
                    </li>
                </ul>
            </div>
        </div>

        <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-stone-200 px-4 py-3">
            <span v-if="invalidIndexes.size > 0" class="text-xs font-medium text-red-700" data-testid="factor-invalid"
                >係数は {{ meta.min_factor }}〜{{ meta.max_factor }} の数（小数2桁まで）で入れてください。</span
            >
            <span v-else-if="saveError" class="text-xs font-medium text-red-700">{{ saveError }}</span>
            <span v-else-if="savedNote" class="text-xs text-emerald-700" data-testid="factor-saved">{{ savedNote }}</span>
            <button
                v-if="data.current?.factors"
                type="button"
                class="h-9 rounded-lg px-3 text-[13px] font-medium text-red-700 hover:bg-red-50 disabled:opacity-40"
                :disabled="isSaving"
                data-testid="factor-stop"
                @click="stop"
            >
                登録をやめる
            </button>
            <button type="button" class="h-9 rounded-lg px-3 text-[13px] text-stone-600 hover:bg-stone-100" :disabled="isSaving" @click="reset">元に戻す</button>
            <button
                type="button"
                class="h-9 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving || invalidIndexes.size > 0 || !isDirty"
                data-testid="factor-save"
                @click="save(parsed as number[])"
            >
                {{ isSaving ? "保存中…" : data.current?.factors ? "保存" : "登録" }}
            </button>
        </footer>
    </section>
</template>
