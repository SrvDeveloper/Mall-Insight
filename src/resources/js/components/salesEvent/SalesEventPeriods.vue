<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { ApiError } from "@/api/client";
import { createSalesEventPeriod, deleteSalesEventPeriod, updateSalesEventPeriod } from "@/api/salesEvents";
import { daysBetween, formatMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";
import type { SalesEventPeriodSummary, SalesEventWithPeriods } from "@/types/api";

/**
 * イベント1つの期間（B-129、K-114）。イベントの一覧の下に、別のセクションとして出す。
 * 期間を初日の順に並べ、これからの期間は倍率の候補、終わった期間は普段の何倍売れたかを出す。期間を押すと、その下に品番ごとの倍率を出す。
 * 期間は一覧の下の欄で追加し、行の「編集」でその行を入力欄にして直せる。
 */

const props = defineProps<{ event: SalesEventWithPeriods; selectedPeriodId: number | null; maxDays: number }>();
const emit = defineEmits<{ select: [periodId: number]; changed: [message: string]; editEvent: []; close: [] }>();

const periods = computed(() => props.event.periods);

const adding = reactive({ startsOn: "", endsOn: "", note: "" });
const editing = ref<{ id: number; startsOn: string; endsOn: string; note: string } | null>(null);
const errors = ref<{ target: "add" | number; messages: Record<string, string[]> } | null>(null);
const isSaving = ref(false);

const addDays = computed(() => daysBetween(adding.startsOn, adding.endsOn));

function errorOf(target: "add" | number, ...keys: string[]): string | null {
    if (errors.value?.target !== target) {
        return null;
    }
    for (const key of keys) {
        const message = errors.value.messages[key]?.[0];
        if (message) {
            return message;
        }
    }
    return null;
}

async function add(): Promise<void> {
    if (isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = null;
    try {
        const period = await createSalesEventPeriod(props.event.id, { starts_on: adding.startsOn, ends_on: adding.endsOn, note: adding.note.trim() || null });
        adding.startsOn = "";
        adding.endsOn = "";
        adding.note = "";
        emit("changed", `${props.event.name}に ${periodLabel(period.starts_on, period.ends_on)} の期間を追加しました。需要予測と在庫予測に反映されます。`);
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = { target: "add", messages: error.errors };
        }
    } finally {
        isSaving.value = false;
    }
}

function startEdit(period: SalesEventPeriodSummary): void {
    editing.value = { id: period.id, startsOn: period.starts_on, endsOn: period.ends_on, note: period.note ?? "" };
    errors.value = null;
}

async function saveEdit(): Promise<void> {
    const value = editing.value;
    if (!value || isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = null;
    try {
        const period = await updateSalesEventPeriod(value.id, { starts_on: value.startsOn, ends_on: value.endsOn, note: value.note.trim() || null });
        editing.value = null;
        emit("changed", `${props.event.name}の期間を ${periodLabel(period.starts_on, period.ends_on)} に変更しました。`);
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = { target: value.id, messages: error.errors };
        }
    } finally {
        isSaving.value = false;
    }
}

async function remove(period: SalesEventPeriodSummary): Promise<void> {
    if (!window.confirm(`${props.event.name}の ${periodLabel(period.starts_on, period.ends_on)} の期間を削除しますか？担当者が入れた倍率も消えます。`)) {
        return;
    }
    try {
        await deleteSalesEventPeriod(period.id);
        emit("changed", `${props.event.name}の ${periodLabel(period.starts_on, period.ends_on)} の期間を削除しました。`);
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

const statusClass = (period: SalesEventPeriodSummary): string =>
    period.status === "ongoing"
        ? "bg-emerald-50 text-emerald-800 ring-emerald-200"
        : period.status === "upcoming"
          ? "bg-sky-50 text-sky-800 ring-sky-200"
          : "bg-stone-100 text-stone-600 ring-stone-200";

const dateInputClass = "h-9 rounded-md border border-stone-300 bg-white px-2 text-sm tabular-nums focus:border-stone-900 focus:outline-none";
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="event-periods-title" data-testid="event-periods">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
            <div class="flex flex-col gap-1">
                <h2 id="event-periods-title" class="text-base font-bold text-stone-900">{{ event.name }}</h2>
                <p class="text-xs text-stone-500">
                    {{ event.malls.map((mall) => mall.label).join("・") }}<template v-if="event.note">・{{ event.note }}</template>
                </p>
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                    data-testid="periods-edit-event"
                    @click="emit('editEvent')"
                >
                    イベントを編集
                </button>
                <button
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="閉じる"
                    data-testid="periods-close"
                    @click="emit('close')"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="m5 5 10 10M15 5 5 15" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] border-separate border-spacing-0 text-left text-[13px]">
                <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:bg-stone-50">
                    <tr>
                        <th scope="col" class="px-4 py-2">期間</th>
                        <th scope="col" class="px-3 py-2">状態</th>
                        <th scope="col" class="px-3 py-2">倍率<span class="ml-1 font-normal text-stone-400">（これからは候補、終わったものは実績）</span></th>
                        <th scope="col" class="px-3 py-2">メモ</th>
                        <th scope="col" class="px-3 py-2"><span class="sr-only">操作</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="periods.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-stone-500" data-testid="no-periods">期間がまだありません。下の欄から追加してください。</td>
                    </tr>
                    <template v-for="period in periods" :key="period.id">
                        <!-- 編集中の行 -->
                        <tr v-if="editing?.id === period.id" class="bg-amber-50/40 [&>*]:border-t [&>*]:border-stone-100" data-testid="period-edit-row">
                            <td colspan="3" class="px-4 py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <input v-model="editing.startsOn" type="date" :class="dateInputClass" aria-label="初日" data-testid="edit-starts-on" />
                                    <span class="text-stone-400">〜</span>
                                    <input v-model="editing.endsOn" type="date" :min="editing.startsOn || undefined" :class="dateInputClass" aria-label="最終日" data-testid="edit-ends-on" />
                                </div>
                                <p v-if="errorOf(period.id, 'starts_on', 'ends_on')" class="mt-1 text-xs text-red-700" data-testid="edit-error">{{ errorOf(period.id, "starts_on", "ends_on") }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    v-model="editing.note"
                                    type="text"
                                    maxlength="500"
                                    placeholder="その回のメモ"
                                    class="h-9 w-full rounded-md border border-stone-300 bg-white px-2 text-sm focus:border-stone-900 focus:outline-none"
                                    aria-label="メモ"
                                />
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <button type="button" class="h-8 rounded-lg px-2.5 text-xs text-stone-600 hover:bg-stone-100" @click="editing = null">取消</button>
                                <button
                                    type="button"
                                    class="h-8 rounded-lg bg-stone-900 px-3 text-xs font-semibold text-white hover:bg-stone-700 disabled:opacity-40"
                                    :disabled="isSaving"
                                    data-testid="edit-save"
                                    @click="saveEdit"
                                >
                                    保存
                                </button>
                            </td>
                        </tr>
                        <tr
                            v-else
                            class="cursor-pointer [&>*]:border-t [&>*]:border-stone-100"
                            :class="selectedPeriodId === period.id ? 'bg-stone-100' : 'hover:bg-stone-50'"
                            :aria-selected="selectedPeriodId === period.id"
                            data-testid="period-row"
                            @click="emit('select', period.id)"
                        >
                            <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                <button
                                    type="button"
                                    class="font-medium text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    :aria-expanded="selectedPeriodId === period.id"
                                    @click.stop="emit('select', period.id)"
                                >
                                    {{ periodLabel(period.starts_on, period.ends_on) }}
                                </button>
                                <span class="ml-1.5 text-[11px] text-stone-400">{{ period.days }}日間</span>
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="statusClass(period)">{{ period.status_label }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex flex-col gap-0.5">
                                    <span
                                        v-for="multiplier in period.multipliers"
                                        :key="multiplier.channel"
                                        class="text-xs whitespace-nowrap text-stone-600 tabular-nums"
                                        data-testid="period-multiplier"
                                    >
                                        <span class="inline-block w-14 text-stone-400">{{ multiplier.label }}</span>
                                        <strong class="font-semibold" :class="multiplier.multiplier === null ? 'text-stone-300' : 'text-stone-900'">{{
                                            formatMultiplier(multiplier.multiplier)
                                        }}</strong>
                                        <template v-if="multiplier.kind === 'candidate'">
                                            <span v-if="multiplier.basis_count === 0" class="ml-1 text-[11px] font-medium text-amber-700">候補なし</span>
                                            <span v-else class="ml-1 text-[11px] text-stone-400">過去{{ multiplier.basis_count }}回から</span>
                                            <span v-if="multiplier.override_count > 0" class="ml-1 rounded bg-stone-100 px-1 text-[10px] text-stone-600"
                                                >{{ multiplier.override_count }}品番は入力</span
                                            >
                                        </template>
                                        <span v-else-if="multiplier.multiplier === null" class="ml-1 text-[11px] text-stone-400">測れません</span>
                                    </span>
                                </span>
                            </td>
                            <td class="max-w-56 truncate px-3 py-2.5 text-xs" :class="period.note ? 'text-stone-600' : 'text-stone-300'" :title="period.note ?? undefined">{{ period.note ?? "—" }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap" @click.stop>
                                <button
                                    type="button"
                                    class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                                    data-testid="period-edit"
                                    @click="startEdit(period)"
                                >
                                    編集
                                </button>
                                <button type="button" class="h-8 rounded-lg px-2.5 text-xs font-medium text-red-700 hover:bg-red-50" data-testid="period-delete" @click="remove(period)">削除</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- 期間を追加 -->
        <form class="flex flex-col gap-1.5 border-t border-stone-200 bg-stone-50/60 px-4 py-3" data-testid="period-add" @submit.prevent="add">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-stone-700">期間を追加</span>
                <input v-model="adding.startsOn" type="date" :class="dateInputClass" aria-label="初日" data-testid="add-starts-on" />
                <span class="text-stone-400">〜</span>
                <input v-model="adding.endsOn" type="date" :min="adding.startsOn || undefined" :class="dateInputClass" aria-label="最終日" data-testid="add-ends-on" />
                <span class="w-14 text-xs text-stone-500 tabular-nums" data-testid="add-days">
                    <template v-if="addDays !== null && addDays > 0"
                        ><span :class="addDays > maxDays ? 'font-semibold text-red-700' : ''">{{ addDays }}日間</span></template
                    >
                </span>
                <input
                    v-model="adding.note"
                    type="text"
                    maxlength="500"
                    placeholder="その回のメモ（任意）"
                    class="h-9 min-w-48 flex-1 rounded-md border border-stone-300 bg-white px-2 text-sm focus:border-stone-900 focus:outline-none"
                    aria-label="その回のメモ"
                />
                <button
                    type="submit"
                    class="h-9 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:opacity-40"
                    :disabled="isSaving || !adding.startsOn || !adding.endsOn"
                    data-testid="add-submit"
                >
                    追加
                </button>
            </div>
            <p v-if="errorOf('add', 'starts_on', 'ends_on')" class="text-xs text-red-700" data-testid="add-error">{{ errorOf("add", "starts_on", "ends_on") }}</p>
        </form>
    </section>
</template>
