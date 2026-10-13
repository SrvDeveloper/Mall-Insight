<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, useTemplateRef } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { createSalesEventPeriod, deleteSalesEventPeriod, updateSalesEventPeriod } from "@/api/salesEvents";
import { daysBetween, formatMultiplier, periodLabel, statusClass } from "@/components/salesEvent/eventFormat";
import { formatDateTime } from "@/components/salesTarget/format";
import type { SalesEventPeriod, SalesEventPeriodSummary, SalesEventWithPeriods } from "@/types/api";

/**
 * イベント1つの期間（B-129、K-114）。イベントの画面に出す。
 * 開催中・これからの期間（初日の早い順）と、終了した期間（新しい順）に分けて並べ、これからの期間は倍率の候補、
 * 終了した期間は普段の何倍売れたかを出す。期間を押すと、期間の画面（品番ごとの倍率）に移る。
 * 期間はモーダルで追加し、行の「編集」でその行を入力欄にして直せる。
 */

const props = defineProps<{ event: SalesEventWithPeriods; maxDays: number; refreshAfterAdd: (period: SalesEventPeriod, message: string) => Promise<void> }>();
const emit = defineEmits<{ changed: [message: string] }>();

const route = useRoute();
const router = useRouter();

const groups = computed(() => [
    { key: "upcoming", label: "開催中・これから", periods: props.event.periods.filter((period) => period.status !== "ended") },
    { key: "ended", label: "終了した期間", periods: props.event.periods.filter((period) => period.status === "ended").reverse() },
]);

/** 期間の画面へ（一覧で選んだモールを引き継ぐ）。 */
const periodLink = (period: SalesEventPeriodSummary) => ({
    name: "sales-event-period",
    params: { eventId: String(props.event.id), periodId: String(period.id) },
    query: typeof route.query.mall === "string" ? { mall: route.query.mall } : {},
});

const adding = reactive({ startsOn: "", endsOn: "", note: "" });
const isAddOpen = ref(false);
const addButton = useTemplateRef<HTMLButtonElement>("addButton");
const addCloseButton = useTemplateRef<HTMLButtonElement>("addCloseButton");
const editing = ref<{ id: number; startsOn: string; endsOn: string; note: string } | null>(null);
const errors = ref<{ target: "add" | number; messages: Record<string, string[]> } | null>(null);
const isSaving = ref(false);
const createdPeriod = ref<SalesEventPeriod | null>(null);
const addError = ref<string | null>(null);

const addDays = computed(() => daysBetween(adding.startsOn, adding.endsOn));

async function openAddForm(): Promise<void> {
    adding.startsOn = "";
    adding.endsOn = "";
    adding.note = "";
    errors.value = null;
    createdPeriod.value = null;
    addError.value = null;
    isAddOpen.value = true;
    document.addEventListener("keydown", onAddKeydown);
    await nextTick();
    addCloseButton.value?.focus();
}

function closeAddForm(): void {
    if (isSaving.value) return;
    isAddOpen.value = false;
    document.removeEventListener("keydown", onAddKeydown);
    void nextTick(() => addButton.value?.focus());
}

function onAddKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeAddForm();
    }
}

onBeforeUnmount(() => document.removeEventListener("keydown", onAddKeydown));

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
    addError.value = null;
    let completed = false;
    try {
        if (createdPeriod.value === null) {
            createdPeriod.value = await createSalesEventPeriod(props.event.id, { starts_on: adding.startsOn, ends_on: adding.endsOn, note: adding.note.trim() || null });
        }
        const period = createdPeriod.value;
        await props.refreshAfterAdd(period, `${props.event.name}に ${periodLabel(period.starts_on, period.ends_on)} の期間を追加しました。需要予測と在庫予測に反映されます。`);
        await nextTick();
        completed = true;
    } catch (error) {
        if (createdPeriod.value !== null) {
            addError.value = "期間は追加されましたが、一覧を更新できませんでした。再読み込みしてください。";
        } else if (error instanceof ApiError && error.isValidationError) {
            errors.value = { target: "add", messages: error.errors };
        } else {
            addError.value = error instanceof ApiError ? error.message : "期間を追加できませんでした。もう一度お試しください。";
        }
    } finally {
        isSaving.value = false;
        if (completed) closeAddForm();
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

const dateInputClass = "h-9 rounded-md border border-stone-300 bg-white px-2 text-sm tabular-nums focus:border-stone-900 focus:outline-none";
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="event-periods-title" data-testid="event-periods">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
            <h2 id="event-periods-title" class="text-[13px] font-semibold text-stone-900">
                期間 <span class="ml-1 font-normal text-stone-500 tabular-nums">{{ event.periods.length }}</span>
            </h2>
            <button
                ref="addButton"
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-stone-900 px-3 text-xs font-semibold text-white hover:bg-stone-700"
                data-testid="open-period-add"
                @click="openAddForm"
            >
                <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M8 3v10M3 8h10" />
                </svg>
                期間を追加
            </button>
        </header>

        <p v-if="event.periods.length === 0" class="px-4 py-10 text-center text-sm text-stone-500" data-testid="no-periods">期間がまだありません。「期間を追加」から登録してください。</p>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-200 border-separate border-spacing-0 text-left text-[13px]">
                <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:bg-white">
                    <tr>
                        <th scope="col" class="px-4 py-2">期間</th>
                        <th scope="col" class="px-3 py-2">倍率<span class="ml-1 font-normal text-stone-400">（開催中・これからは候補／終了は実績）</span></th>
                        <th scope="col" class="px-3 py-2">その回のメモ</th>
                        <th scope="col" class="px-3 py-2">更新</th>
                        <th scope="col" class="px-3 py-2"><span class="sr-only">操作</span></th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.key" :data-testid="`period-group-${group.key}`">
                    <tr>
                        <th colspan="5" scope="colgroup" class="border-b border-stone-200 bg-stone-50 px-4 py-1.5 text-left text-xs font-semibold text-stone-600">
                            {{ group.label }}<span class="ml-1.5 font-normal text-stone-400 tabular-nums">{{ group.periods.length }}件</span>
                        </th>
                    </tr>
                    <tr v-if="group.periods.length === 0">
                        <td colspan="5" class="border-b border-stone-100 px-4 py-3 text-xs text-stone-400">
                            {{ group.key === "ended" ? "終了した期間はありません。" : "これからの期間はありません。" }}
                        </td>
                    </tr>
                    <template v-for="period in group.periods" :key="period.id">
                        <!-- 編集中の行 -->
                        <tr v-if="editing?.id === period.id" class="bg-amber-50/40 *:border-b *:border-stone-100" data-testid="period-edit-row">
                            <td colspan="4" class="px-4 py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <input v-model="editing.startsOn" type="date" :class="dateInputClass" aria-label="初日" data-testid="edit-starts-on" />
                                    <span class="text-stone-400">〜</span>
                                    <input v-model="editing.endsOn" type="date" :min="editing.startsOn || undefined" :class="dateInputClass" aria-label="最終日" data-testid="edit-ends-on" />
                                    <input
                                        v-model="editing.note"
                                        type="text"
                                        maxlength="500"
                                        placeholder="その回のメモ"
                                        class="h-9 min-w-48 flex-1 rounded-md border border-stone-300 bg-white px-2 text-sm focus:border-stone-900 focus:outline-none"
                                        aria-label="メモ"
                                    />
                                </div>
                                <p v-if="errorOf(period.id, 'starts_on', 'ends_on')" class="mt-1 text-xs text-red-700" data-testid="edit-error">{{ errorOf(period.id, "starts_on", "ends_on") }}</p>
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
                        <tr v-else class="cursor-pointer *:border-b *:border-stone-100 hover:bg-stone-50" data-testid="period-row" @click="router.push(periodLink(period))">
                            <td class="px-4 py-2.5 align-top whitespace-nowrap tabular-nums">
                                <RouterLink
                                    :to="periodLink(period)"
                                    class="font-medium text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    data-testid="period-link"
                                    @click.stop
                                >
                                    {{ periodLabel(period.starts_on, period.ends_on) }}
                                </RouterLink>
                                <span class="ml-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="statusClass(period.status)">{{ period.status_label }}</span>
                                <span class="ml-1.5 text-[11px] text-stone-400">{{ period.days }}日間</span>
                            </td>
                            <td class="px-3 py-2.5 align-top">
                                <span class="flex flex-col gap-0.5">
                                    <span
                                        v-for="multiplier in period.multipliers"
                                        :key="multiplier.channel"
                                        class="text-xs whitespace-nowrap text-stone-600 tabular-nums"
                                        data-testid="period-multiplier"
                                    >
                                        <span v-if="period.multipliers.length > 1" class="inline-block w-14 text-stone-400">{{ multiplier.label }}</span>
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
                            <td class="px-3 py-2.5 align-top text-xs text-stone-600">
                                <span v-if="period.note" class="block max-w-64 truncate" :title="period.note">{{ period.note }}</span>
                                <span v-else class="text-stone-300">—</span>
                            </td>
                            <td class="px-3 py-2.5 align-top text-[11px] whitespace-nowrap text-stone-500 tabular-nums">
                                {{ period.updated_at ? formatDateTime(period.updated_at) : "" }} {{ period.updated_by ?? "" }}
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap" @click.stop>
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
    </section>

    <div v-if="isAddOpen" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" data-testid="period-add-backdrop" @click.self="closeAddForm">
        <div role="dialog" aria-modal="true" aria-labelledby="period-add-title" class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5">
            <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div class="flex flex-col gap-0.5">
                    <h2 id="period-add-title" class="text-base font-bold text-stone-900">期間を追加</h2>
                    <p class="text-xs text-stone-500">{{ event.name }}に開催期間を追加します。期間は{{ maxDays }}日までです。</p>
                </div>
                <button
                    ref="addCloseButton"
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="閉じる"
                    :disabled="isSaving"
                    data-testid="period-add-close"
                    @click="closeAddForm"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="m5 5 10 10M15 5 5 15" />
                    </svg>
                </button>
            </header>
            <form class="flex min-h-0 flex-col" :aria-busy="isSaving" data-testid="period-add" @submit.prevent="add">
                <div class="flex flex-col gap-4 overflow-y-auto px-5 py-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5 text-xs font-medium text-stone-700">
                            初日
                            <input v-model="adding.startsOn" type="date" :disabled="isSaving || createdPeriod !== null" :class="[dateInputClass, 'w-full']" data-testid="add-starts-on" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-xs font-medium text-stone-700">
                            最終日
                            <input
                                v-model="adding.endsOn"
                                type="date"
                                :min="adding.startsOn || undefined"
                                :disabled="isSaving || createdPeriod !== null"
                                :class="[dateInputClass, 'w-full']"
                                data-testid="add-ends-on"
                            />
                        </label>
                    </div>
                    <p class="min-h-4 text-xs text-stone-500 tabular-nums" data-testid="add-days">
                        <span v-if="addDays !== null && addDays > 0" :class="addDays > maxDays ? 'font-semibold text-red-700' : ''">{{ addDays }}日間</span>
                    </p>
                    <label class="flex flex-col gap-1.5 text-xs font-medium text-stone-700">
                        その回のメモ（任意）
                        <input
                            v-model="adding.note"
                            type="text"
                            maxlength="500"
                            :disabled="isSaving || createdPeriod !== null"
                            class="h-9 w-full rounded-md border border-stone-300 bg-white px-3 text-sm focus:border-stone-900 focus:outline-none"
                        />
                    </label>
                    <p v-if="errorOf('add', 'starts_on', 'ends_on')" class="text-xs text-red-700" data-testid="add-error">{{ errorOf("add", "starts_on", "ends_on") }}</p>
                    <p v-if="addError" class="text-xs text-red-700" role="alert" data-testid="add-error">{{ addError }}</p>
                </div>
                <footer class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4">
                    <button type="button" class="h-9 rounded-lg px-4 text-[13px] font-medium text-stone-600 hover:bg-stone-100 disabled:opacity-40" :disabled="isSaving" @click="closeAddForm">
                        キャンセル
                    </button>
                    <button
                        type="submit"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:opacity-40"
                        :disabled="isSaving || (createdPeriod === null && (!adding.startsOn || !adding.endsOn))"
                        data-testid="add-submit"
                    >
                        <svg v-if="isSaving" class="size-4 animate-spin" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                        </svg>
                        {{ isSaving ? "追加中…" : createdPeriod === null ? "追加" : "一覧を再読み込み" }}
                    </button>
                </footer>
            </form>
        </div>
    </div>
</template>
