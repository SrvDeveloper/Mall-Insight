<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { ApiError } from "@/api/client";
import { saveSalesEventMultipliers } from "@/api/salesEvents";
import { MAX_MULTIPLIER, formatChange, formatCount, formatMultiplier, parseMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";
import type { SalesEventCandidateItem, SalesEventDetailResponse, SalesEventMeasuredItem } from "@/types/api";

/**
 * イベント1つの倍率（B-129、K-112）。一覧の下に、別のセクションとして出す。
 *
 * - これからのイベント：チャネルごとに、候補の根拠（同じ名前の過去のイベントで、普段なら売れた数と売れた数）と、品番ごとの候補・使う倍率・
 *   イベントの期間（今日以降）の普段の予測・倍率で増える数を出す。使う倍率を入れた品番は、候補の代わりにその倍率で需要予測を増やす。
 *   空欄の品番は候補を使う。「全品番に入れる」で、同じ倍率をまとめて入れられる。
 * - 終わったイベント：チャネルごとに、普段の何倍売れたか（品番ごとと全体）を出す。倍率は需要予測に使わないため直せない。
 */

const props = defineProps<{ detail: SalesEventDetailResponse; isLoading: boolean }>();
const emit = defineEmits<{ saved: [detail: SalesEventDetailResponse]; close: [] }>();

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
        const saved = await saveSalesEventMultipliers(event.value.id, multipliers);
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
    return count === 0 ? "同じ名前の過去のイベントがありません" : `同じ名前の過去のイベント ${count}回（新しい方から${props.detail.meta.recent_events}回まで）`;
}

const isMeasured = (item: SalesEventCandidateItem | SalesEventMeasuredItem): item is SalesEventMeasuredItem => "ratio" in item;
const measuredItems = (items: (SalesEventCandidateItem | SalesEventMeasuredItem)[]): SalesEventMeasuredItem[] => items.filter(isMeasured);
const candidateItems = (items: (SalesEventCandidateItem | SalesEventMeasuredItem)[]): SalesEventCandidateItem[] => items.filter((item): item is SalesEventCandidateItem => !isMeasured(item));

const statusClass = computed(() =>
    event.value.status === "ongoing"
        ? "bg-emerald-50 text-emerald-800 ring-emerald-200"
        : event.value.status === "upcoming"
          ? "bg-sky-50 text-sky-800 ring-sky-200"
          : "bg-stone-100 text-stone-600 ring-stone-200",
);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-stone-200 bg-white transition-opacity"
        :class="isLoading ? 'opacity-60' : ''"
        aria-labelledby="event-detail-title"
        data-testid="event-detail"
    >
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="event-detail-title" class="text-base font-bold text-stone-900">{{ event.name }}</h2>
                    <span class="rounded-full px-2 py-px text-[11px] font-semibold ring-1" :class="statusClass">{{ event.status_label }}</span>
                </div>
                <p class="text-xs text-stone-500 tabular-nums">
                    {{ periodLabel(event.starts_on, event.ends_on) }}（{{ event.days }}日間）・{{ event.malls.map((mall) => mall.label).join("・") }}
                    <template v-if="!isEnded && event.remaining_days < event.days">・今日からの残り {{ event.remaining_days }}日に倍率を掛けます</template>
                </p>
            </div>
            <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                aria-label="閉じる"
                data-testid="detail-close"
                @click="emit('close')"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="m5 5 10 10M15 5 5 15" />
                </svg>
            </button>
        </header>

        <div v-for="channel in event.channels" :key="channel.channel" class="border-b border-stone-200 last:border-b-0" :data-testid="`detail-${channel.channel}`">
            <!-- 終わったイベント：普段の何倍売れたか -->
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
                    <table class="w-full min-w-[36rem] border-separate border-spacing-0 text-sm">
                        <thead class="text-xs text-stone-500 [&_th]:border-y [&_th]:border-stone-200 [&_th]:bg-stone-50 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                            <tr>
                                <th scope="col" class="text-left">品番</th>
                                <th scope="col" class="text-right">普段なら売れた数</th>
                                <th scope="col" class="text-right">売れた数</th>
                                <th scope="col" class="text-right">普段の何倍</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in measuredItems(channel.items)" :key="item.item_no" class="[&>*]:border-b [&>*]:border-stone-100 [&>*]:px-4 [&>*]:py-2" data-testid="measured-row">
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

            <!-- これからのイベント：候補と使う倍率 -->
            <template v-else>
                <div class="flex flex-col gap-2 px-4 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <h3 class="text-sm font-semibold text-stone-900">{{ channel.label }}</h3>
                        <p class="text-[13px] text-stone-700 tabular-nums" data-testid="candidate-summary">
                            <template v-if="channel.overall !== null">
                                全体の候補 <strong class="text-base text-stone-900">{{ formatMultiplier(channel.overall) }}</strong
                                >（{{ basisLabel(channel.basis.length) }}で、普段なら {{ formatCount(channel.expected) }}個のところ {{ formatCount(channel.actual) }}個）
                            </template>
                            <span v-else class="font-medium text-amber-700" data-testid="no-candidate"
                                >{{ basisLabel(0) }}。過去のイベントを同じ名前で登録するか、使う倍率を入れてください（入れなければ、イベントの日も普段どおりに予測します）。</span
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
                    <table class="w-full min-w-[52rem] border-separate border-spacing-0 text-sm">
                        <thead class="text-xs text-stone-500 [&_th]:border-y [&_th]:border-stone-200 [&_th]:bg-stone-50 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                            <tr>
                                <th scope="col" class="text-left">品番</th>
                                <th scope="col" class="text-right">過去のイベント<span class="block text-[10px] font-normal text-stone-400">普段なら→売れた数</span></th>
                                <th scope="col" class="text-right">候補</th>
                                <th scope="col" class="w-32 text-right">使う倍率<span class="block text-[10px] font-normal text-stone-400">空欄は候補</span></th>
                                <th scope="col" class="text-right">普段の予測<span class="block text-[10px] font-normal text-stone-400">イベントの期間</span></th>
                                <th scope="col" class="text-right">増える数<span class="block text-[10px] font-normal text-stone-400">保存した倍率で計算</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in candidateItems(channel.items)" :key="item.item_no" class="[&>*]:border-b [&>*]:border-stone-100 [&>*]:px-4 [&>*]:py-1.5" data-testid="candidate-row">
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
                                        v-if="item.uses_overall"
                                        class="ml-1 rounded bg-stone-100 px-1 text-[10px] text-stone-500"
                                        :title="`過去のイベントで普段なら売れた数が${detail.meta.min_expected}個未満のため、全体の倍率を使います`"
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
                                        :class="invalidKeys.has(`${channel.channel}|${item.item_no}`) ? 'border-red-500 text-red-700 focus:border-red-600' : 'border-stone-300 focus:border-stone-900'"
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

        <footer v-if="!isEnded" class="flex flex-wrap items-center justify-end gap-3 border-t border-stone-200 px-4 py-3">
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
