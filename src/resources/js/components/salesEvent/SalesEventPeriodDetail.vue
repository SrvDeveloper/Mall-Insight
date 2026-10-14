<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { saveSalesEventPeriodMultipliers } from "@/api/salesEvents";
import { MAX_MULTIPLIER, formatChange, formatCount, formatMultiplier, parseMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";
import type { SalesEventCandidateItem, SalesEventMeasuredItem, SalesEventPeriodDetailResponse } from "@/types/api";

/**
 * イベントの期間1つの倍率（B-129、K-112・K-114）。期間の画面に出す（期間・イベントの名前は画面の見出しに出す）。
 *
 * - これからの期間：チャネルごとに、候補の根拠（同じイベントの過去の期間で、普段なら売れた数と売れた数）と、品番ごとの候補・使う倍率・
 *   期間（今日以降）の普段の予測・倍率で増える数を出す。使う倍率を入れた品番は、候補の代わりにその倍率でこの期間の需要予測を増やす。
 *   空欄の品番は候補を使う。「全品番に入れる」で、同じ倍率をまとめて入れられる。
 * - 終了した期間：チャネルごとに、普段の何倍売れたか（品番ごとと全体）を出す。倍率は需要予測に使わないため直せない。
 */

const props = defineProps<{ detail: SalesEventPeriodDetailResponse; isLoading: boolean }>();
const emit = defineEmits<{ saved: [detail: SalesEventPeriodDetailResponse] }>();

const event = computed(() => props.detail.data);
const isEnded = computed(() => event.value.status === "ended");

/** 入力中の倍率。キーは「チャネル|品番」。空欄は候補を使う。 */
const inputs = ref<Record<string, string>>({});
const bulk = ref<Record<string, string>>({});
const isSaving = ref(false);
const saveError = ref<string | null>(null);
const savedNote = ref<string | null>(null);

const keyOf = (channel: string, itemNo: string): string => `${channel}|${itemNo}`;

function reset(): void {
    const values: Record<string, string> = {};
    for (const channel of props.detail.data.channels) {
        if (channel.kind === "candidate") {
            for (const item of channel.items) {
                values[keyOf(channel.channel, item.item_no)] = item.override === null ? "" : String(item.override);
            }
        }
    }
    inputs.value = values;
    bulk.value = {};
    saveError.value = null;
}

watch(() => props.detail, reset, { immediate: true });

const invalidKeys = computed(
    () =>
        new Set(
            Object.entries(inputs.value)
                .filter(([, value]) => parseMultiplier(value) === undefined)
                .map(([key]) => key),
        ),
);

/** 保存した値から変えたか。 */
const isDirty = computed(() =>
    props.detail.data.channels.some(
        (channel) => channel.kind === "candidate" && channel.items.some((item) => (inputs.value[keyOf(channel.channel, item.item_no)] ?? "") !== (item.override === null ? "" : String(item.override))),
    ),
);

function fillAll(channel: string, items: SalesEventCandidateItem[]): void {
    const value = bulk.value[channel] ?? "";
    if (parseMultiplier(value) === undefined) {
        return;
    }
    const next = { ...inputs.value };
    for (const item of items) {
        next[keyOf(channel, item.item_no)] = value.trim();
    }
    inputs.value = next;
}

function clearAll(channel: string, items: SalesEventCandidateItem[]): void {
    const next = { ...inputs.value };
    for (const item of items) {
        next[keyOf(channel, item.item_no)] = "";
    }
    inputs.value = next;
}

async function save(): Promise<void> {
    if (isSaving.value || invalidKeys.value.size > 0) {
        return;
    }
    isSaving.value = true;
    saveError.value = null;
    savedNote.value = null;
    const multipliers = Object.entries(inputs.value).flatMap(([key, value]) => {
        const parsed = parseMultiplier(value);
        if (parsed === null || parsed === undefined) {
            return [];
        }
        const [channel, itemNo] = key.split("|") as [string, string];
        return [{ item_no: itemNo, channel, multiplier: parsed }];
    });
    try {
        const saved = await saveSalesEventPeriodMultipliers(event.value.id, multipliers);
        savedNote.value = multipliers.length > 0 ? `${multipliers.length}品番の倍率を保存しました。需要予測と在庫予測に反映されます。` : "すべての品番を候補の倍率に戻しました。";
        emit("saved", saved);
    } catch (error) {
        saveError.value = error instanceof ApiError ? error.message : "倍率を保存できませんでした。";
    } finally {
        isSaving.value = false;
    }
}

/** 候補の根拠の説明（「楽天スーパーセール 3回（新しい方から）」）。 */
function basisLabel(count: number): string {
    return count === 0 ? "このイベントの終了した期間がありません" : `このイベントの過去の期間 ${count}回（新しい方から${props.detail.meta.recent_events}回まで）`;
}

const isMeasured = (item: SalesEventCandidateItem | SalesEventMeasuredItem): item is SalesEventMeasuredItem => "ratio" in item;
const measuredItems = (items: (SalesEventCandidateItem | SalesEventMeasuredItem)[]): SalesEventMeasuredItem[] =>
    items.filter(isMeasured).sort((a, b) => {
        if (a.ratio === null) return b.ratio === null ? a.item_no.localeCompare(b.item_no) : 1;
        if (b.ratio === null) return -1;
        return b.ratio - a.ratio || a.item_no.localeCompare(b.item_no);
    });
const candidateItems = (items: (SalesEventCandidateItem | SalesEventMeasuredItem)[]): SalesEventCandidateItem[] => items.filter((item): item is SalesEventCandidateItem => !isMeasured(item));

/** 根拠の回数に応じて、候補を控えめにしたか（B-135、K-127・K-128）。 */
const isRestrained = (candidate: number | null, raw: number | null): boolean => candidate !== null && raw !== null && Math.abs(candidate - raw) >= 0.005;
</script>

<template>
    <section class="rounded-xl border border-stone-200 bg-white transition-opacity" :class="isLoading ? 'opacity-60' : ''" aria-label="品番ごとの倍率" data-testid="event-detail">
        <div>
            <div v-for="channel in event.channels" :key="channel.channel" class="border-b border-stone-200 last:border-b-0" :data-testid="`detail-${channel.channel}`">
                <!-- 終了した期間：普段の何倍売れたか -->
                <template v-if="channel.kind === 'measured'">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3">
                        <h3 class="text-sm font-semibold text-stone-900">{{ channel.label }}</h3>
                        <p v-if="channel.note" class="text-xs font-medium text-amber-700" data-testid="measured-note">{{ channel.note }}</p>
                        <p v-else class="text-[13px] text-stone-700 tabular-nums" data-testid="measured-summary">
                            普段の <strong class="text-base text-stone-900">{{ formatMultiplier(channel.overall) }}</strong> 売れました（普段なら {{ formatCount(channel.expected) }}個のところ
                            {{ formatCount(channel.actual) }}個）
                        </p>
                    </div>
                    <div v-if="channel.items.length > 0" class="overflow-x-auto">
                        <table class="w-full min-w-xl border-separate border-spacing-0 text-sm">
                            <thead class="text-xs text-stone-500 [&_th]:border-y [&_th]:border-stone-200 [&_th]:bg-stone-50 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                                <tr>
                                    <th scope="col" class="text-left">品番</th>
                                    <th scope="col" class="text-right">通常時</th>
                                    <th scope="col" class="text-right">開催時</th>
                                    <th scope="col" class="text-right">変化率</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in measuredItems(channel.items)" :key="item.item_no" class="*:border-b *:border-stone-100 *:px-4 *:py-2" data-testid="measured-row">
                                    <th scope="row" class="text-left font-normal">
                                        <span class="font-mono text-[13px] font-medium text-stone-900">{{ item.item_no }}</span>
                                        <span class="ml-2 text-[11px] text-stone-500">{{ item.brand }}・{{ item.category }}</span>
                                    </th>
                                    <td class="text-right text-stone-600 tabular-nums">{{ formatCount(item.expected) }}</td>
                                    <td class="text-right text-stone-600 tabular-nums">{{ formatCount(item.actual) }}</td>
                                    <td class="text-right font-semibold text-stone-900 tabular-nums" :title="item.ratio === null ? '普段の予測が出せなかったため、測れません' : undefined">
                                        {{ formatMultiplier(item.ratio) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <!-- これからの期間：候補と使う倍率 -->
                <template v-else>
                    <div class="flex flex-col gap-2 px-4 py-3">
                        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <h3 class="text-sm font-semibold text-stone-900">{{ channel.label }}</h3>
                            <p class="text-[13px] text-stone-700 tabular-nums" data-testid="candidate-summary">
                                <template v-if="channel.overall !== null">
                                    全体の候補 <strong class="text-base text-stone-900">{{ formatMultiplier(channel.overall) }}</strong
                                    >（{{ basisLabel(channel.basis.length) }}で、普段なら {{ formatCount(channel.expected) }}個のところ {{ formatCount(channel.actual) }}個）
                                    <span v-if="isRestrained(channel.overall, channel.overall_raw)" class="text-xs text-stone-500" data-testid="candidate-restrained"
                                        >過去の期間では {{ formatMultiplier(channel.overall_raw) }}。根拠が{{ channel.basis.length }}回のため、増える分を控えめにしています。</span
                                    >
                                </template>
                                <span v-else class="font-medium text-amber-700" data-testid="no-candidate"
                                    >{{ basisLabel(0) }}。このイベントに過去の期間を登録するか、使う倍率を入れてください（入れなければ、イベントの日も普段どおりに予測します）。</span
                                >
                            </p>
                        </div>
                        <ul v-if="channel.basis.length > 0" class="flex flex-wrap gap-1.5" aria-label="候補の根拠" data-testid="candidate-basis">
                            <li v-for="basis in channel.basis" :key="basis.id" class="rounded-full border border-stone-200 bg-stone-50 px-2.5 py-0.5 text-[11px] text-stone-600 tabular-nums">
                                {{ periodLabel(basis.starts_on, basis.ends_on) }}
                                <strong class="font-semibold text-stone-900">{{ formatMultiplier(basis.ratio) }}</strong>
                                <span class="text-stone-400">（{{ formatCount(basis.expected) }}→{{ formatCount(basis.actual) }}個）</span>
                            </li>
                        </ul>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <label class="inline-flex items-center gap-1.5 text-xs text-stone-600">
                                全品番に
                                <input
                                    v-model="bulk[channel.channel]"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="例：3"
                                    class="h-8 w-16 rounded-md border border-stone-300 bg-white px-2 text-right text-sm tabular-nums focus:border-stone-900 focus:outline-none"
                                    :aria-label="`${channel.label}の全品番に入れる倍率`"
                                    :data-testid="`bulk-${channel.channel}`"
                                />
                                倍を
                            </label>
                            <button
                                type="button"
                                class="h-8 rounded-lg border border-stone-300 bg-white px-2.5 text-xs font-medium text-stone-900 hover:bg-stone-100 disabled:opacity-40"
                                :disabled="!bulk[channel.channel] || parseMultiplier(bulk[channel.channel] ?? '') === undefined"
                                :data-testid="`bulk-apply-${channel.channel}`"
                                @click="fillAll(channel.channel, candidateItems(channel.items))"
                            >
                                入れる
                            </button>
                            <button
                                type="button"
                                class="h-8 rounded-lg px-2.5 text-xs text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                                :data-testid="`clear-${channel.channel}`"
                                @click="clearAll(channel.channel, candidateItems(channel.items))"
                            >
                                すべて候補に戻す
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-208 border-separate border-spacing-0 text-sm">
                            <thead class="text-xs text-stone-500 [&_th]:border-y [&_th]:border-stone-200 [&_th]:bg-stone-50 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                                <tr>
                                    <th scope="col" class="text-left">品番</th>
                                    <th scope="col" class="text-right">過去の期間<span class="block text-[10px] font-normal text-stone-400">普段なら→売れた数</span></th>
                                    <th scope="col" class="text-right">候補</th>
                                    <th scope="col" class="w-32 text-right">使う倍率<span class="block text-[10px] font-normal text-stone-400">空欄は候補</span></th>
                                    <th scope="col" class="text-right">普段の予測<span class="block text-[10px] font-normal text-stone-400">この期間</span></th>
                                    <th scope="col" class="text-right">増える数<span class="block text-[10px] font-normal text-stone-400">保存した倍率で計算</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in candidateItems(channel.items)" :key="item.item_no" class="*:border-b *:border-stone-100 *:px-4 *:py-1.5" data-testid="candidate-row">
                                    <th scope="row" class="text-left font-normal">
                                        <span class="font-mono text-[13px] font-medium text-stone-900">{{ item.item_no }}</span>
                                        <span class="ml-2 text-[11px] text-stone-500">{{ item.brand }}・{{ item.category }}</span>
                                    </th>
                                    <td class="text-right text-stone-500 tabular-nums">
                                        <template v-if="item.past_expected !== null">{{ formatCount(item.past_expected) }}→{{ formatCount(item.past_actual) }}</template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="text-right whitespace-nowrap tabular-nums" :class="item.candidate === null ? 'text-stone-300' : 'text-stone-700'" data-testid="candidate-value">
                                        {{ formatMultiplier(item.candidate) }}
                                        <span
                                            v-if="isRestrained(item.candidate, item.candidate_raw)"
                                            class="ml-1 text-[10px] text-stone-400"
                                            :title="`過去の期間では ${formatMultiplier(item.candidate_raw)}。根拠の回数が少ないため、増える分を控えめにしています`"
                                            data-testid="candidate-raw"
                                            >←{{ formatMultiplier(item.candidate_raw) }}</span
                                        >
                                        <span
                                            v-if="item.uses_overall"
                                            class="ml-1 rounded bg-stone-100 px-1 text-[10px] text-stone-500"
                                            :title="`過去の期間で普段なら売れた数が${detail.meta.min_expected}個未満のため、全体の倍率を使います`"
                                            data-testid="uses-overall"
                                            >全体</span
                                        >
                                    </td>
                                    <td class="text-right">
                                        <input
                                            v-model="inputs[`${channel.channel}|${item.item_no}`]"
                                            type="text"
                                            inputmode="decimal"
                                            :placeholder="item.candidate === null ? '—' : item.candidate.toFixed(2)"
                                            class="h-8 w-24 rounded-md border bg-white px-2 text-right text-sm tabular-nums placeholder:text-stone-300 focus:outline-none"
                                            :class="
                                                invalidKeys.has(`${channel.channel}|${item.item_no}`) ? 'border-red-500 text-red-700 focus:border-red-600' : 'border-stone-300 focus:border-stone-900'
                                            "
                                            :aria-label="`${item.item_no} の${channel.label}の倍率`"
                                            :aria-invalid="invalidKeys.has(`${channel.channel}|${item.item_no}`) || undefined"
                                            data-testid="multiplier-input"
                                        />
                                    </td>
                                    <td class="text-right text-stone-600 tabular-nums">{{ item.usual === null ? "予測不能" : formatCount(item.usual) }}</td>
                                    <td class="text-right font-semibold tabular-nums" :class="item.extra ? 'text-stone-900' : 'text-stone-400'" data-testid="extra">
                                        {{ formatChange(item.extra) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>

        <footer v-if="!isEnded" class="sticky bottom-0 flex flex-wrap items-center justify-end gap-3 rounded-b-xl border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur">
            <span v-if="invalidKeys.size > 0" class="text-xs font-medium text-red-700" data-testid="invalid-note">倍率は 0〜{{ MAX_MULTIPLIER }} の数（小数2桁まで）で入れてください。</span>
            <span v-else-if="saveError" class="text-xs font-medium text-red-700" data-testid="save-error">{{ saveError }}</span>
            <span v-else-if="savedNote && !isDirty" class="text-xs text-emerald-700" data-testid="saved-note">{{ savedNote }}</span>
            <span v-else class="text-[11px] text-stone-400">増える数は保存した倍率で計算しています。保存すると需要予測と在庫予測に反映されます。</span>
            <button type="button" class="h-9 rounded-lg px-3 text-[13px] text-stone-600 hover:bg-stone-100 disabled:opacity-40" :disabled="!isDirty || isSaving" @click="reset">元に戻す</button>
            <button
                type="button"
                class="h-9 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!isDirty || isSaving || invalidKeys.size > 0"
                data-testid="save-multipliers"
                @click="save"
            >
                {{ isSaving ? "保存中…" : "倍率を保存" }}
            </button>
        </footer>
    </section>
</template>
