<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from "vue";
import { ApiError } from "@/api/client";
import { createRatioCategory, renameRatioCategory, saveMonthlySalesRatio } from "@/api/salesTargets";
import { formatDateTime, formatRatio, monthLabel } from "@/components/salesTarget/format";
import type { SalesTargetResponse } from "@/types/api";

/**
 * 月別販売比率の変更（バックログ B-110、決定記録 K-049②・K-056・K-061）。区分ごとに、4月～翌3月の12か月の比率（%、小数第2位まで）を入れる。
 * 12か月の合計が100.00%のときだけ保存でき、保存するたびに新しい行として記録する。
 * 区分は足したり名前を変えたりできる。足した区分は比率がまだ無いので、続けて比率を入れて保存する。
 */

type Category = SalesTargetResponse["meta"]["ratio_categories"][number];

const props = defineProps<{ categories: Category[]; months: string[] }>();
const emit = defineEmits<{ saved: [message: string] }>();

/** 区分のID => 入力中の12か月の比率（%の文字列） */
const drafts = ref<Record<number, string[]>>({});
const savingId = ref<number | null>(null);
const errors = ref<Record<number, string | null>>({});

watch(
    () => props.categories,
    (categories) => {
        drafts.value = Object.fromEntries(categories.map((category) => [category.id, (category.ratio?.ratios ?? Array(12).fill(0)).map((value) => formatRatio(value))]));
        errors.value = {};
    },
    { immediate: true },
);

/** 入力した%を1万分率の整数にする。読めなければ null。 */
function toBasisPoints(value: string): number | null {
    const number = Number(String(value).trim());
    return String(value).trim() !== "" && Number.isFinite(number) && number >= 0 ? Math.round(number * 100) : null;
}

const rows = computed(() =>
    props.categories.map((category) => {
        const values = (drafts.value[category.id] ?? []).map(toBasisPoints);
        const isValid = values.every((value) => value !== null);
        const total = values.reduce<number>((sum, value) => sum + (value ?? 0), 0);
        const saved = category.ratio?.ratios ?? null;
        const isChanged = saved === null || values.some((value, index) => value !== saved[index]);
        return { category, values, isValid, total, isChanged, canSave: isValid && total === 10000 && isChanged };
    }),
);

function errorMessage(error: unknown, fallback: string): string {
    return error instanceof ApiError && error.isValidationError ? (Object.values(error.errors).flat()[0] ?? error.message) : fallback;
}

async function save(category: Category, values: (number | null)[]): Promise<void> {
    savingId.value = category.id;
    errors.value = { ...errors.value, [category.id]: null };
    try {
        await saveMonthlySalesRatio(
            category.id,
            values.map((value) => value ?? 0),
        );
        emit("saved", `${category.name}の月別販売比率を保存しました。この区分を使う品番の月ごとの目標が変わります。`);
    } catch (error) {
        errors.value = { ...errors.value, [category.id]: errorMessage(error, "保存できませんでした。") };
    } finally {
        savingId.value = null;
    }
}

// 区分の名前を変える
const renamingId = ref<number | null>(null);
const newName = ref("");
const renameError = ref<string | null>(null);
const renameInput = useTemplateRef<HTMLInputElement[]>("renameInput");

async function startRename(category: Category): Promise<void> {
    renamingId.value = category.id;
    newName.value = category.name;
    renameError.value = null;
    await nextTick();
    renameInput.value?.[0]?.select();
}

async function rename(category: Category): Promise<void> {
    if (newName.value.trim() === category.name) {
        renamingId.value = null;
        return;
    }
    renameError.value = null;
    try {
        await renameRatioCategory(category.id, newName.value);
        renamingId.value = null;
        emit("saved", `区分「${category.name}」の名前を「${newName.value.trim()}」に変えました。`);
    } catch (error) {
        renameError.value = errorMessage(error, "名前を変えられませんでした。");
    }
}

// 区分を足す
const isAdding = ref(false);
const addName = ref("");
const addError = ref<string | null>(null);
const isCreating = ref(false);

async function add(): Promise<void> {
    if (addName.value.trim() === "" || isCreating.value) {
        return;
    }
    isCreating.value = true;
    addError.value = null;
    try {
        await createRatioCategory(addName.value);
        const name = addName.value.trim();
        addName.value = "";
        isAdding.value = false;
        emit("saved", `区分「${name}」を足しました。12か月の比率を入れて保存してください。`);
    } catch (error) {
        addError.value = errorMessage(error, "区分を足せませんでした。");
    } finally {
        isCreating.value = false;
    }
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[66rem] border-separate border-spacing-0 text-left text-[13px]" data-testid="ratio-editor">
            <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                <tr>
                    <th scope="col" class="w-52 px-4 py-2.5">区分</th>
                    <th v-for="month in months" :key="month" scope="col" class="px-1 py-2.5 text-center tabular-nums">{{ monthLabel(month) }}</th>
                    <th scope="col" class="px-3 py-2.5 text-right">合計</th>
                    <th scope="col" class="px-4 py-2.5"><span class="sr-only">保存</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.category.id" class="[&>*]:border-t [&>*]:border-stone-100" :data-testid="`ratio-row-${row.category.id}`">
                    <th scope="row" class="px-4 py-2.5 text-left align-top font-normal">
                        <form v-if="renamingId === row.category.id" class="flex flex-col gap-1" @submit.prevent="rename(row.category)">
                            <div class="flex items-center gap-1">
                                <input
                                    ref="renameInput"
                                    v-model="newName"
                                    type="text"
                                    maxlength="50"
                                    class="h-8 min-w-0 flex-1 rounded-md border border-stone-900 bg-white px-2 text-[13px] text-stone-900 focus:outline-none"
                                    :aria-label="`${row.category.name}の新しい名前`"
                                    data-testid="rename-input"
                                    @keydown.esc.stop="renamingId = null"
                                />
                                <button type="submit" class="h-8 rounded-md bg-stone-900 px-2 text-xs font-semibold text-white hover:bg-stone-700" data-testid="rename-save">保存</button>
                                <button type="button" class="h-8 rounded-md px-1.5 text-xs text-stone-600 hover:bg-stone-100" @click="renamingId = null">取消</button>
                            </div>
                            <span v-if="renameError" class="text-[11px] text-red-700">{{ renameError }}</span>
                        </form>
                        <template v-else>
                            <span class="flex items-center gap-1">
                                <span class="text-sm font-medium text-stone-900">{{ row.category.name }}</span>
                                <button
                                    type="button"
                                    class="flex size-6 items-center justify-center rounded-md text-stone-400 hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-stone-900"
                                    :aria-label="`${row.category.name}の名前を変える`"
                                    title="名前を変える"
                                    :data-testid="`rename-${row.category.id}`"
                                    @click="startRename(row.category)"
                                >
                                    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M11.5 2.5a1.4 1.4 0 0 1 2 2L5 13l-2.75.75L3 11Z" />
                                        <path d="m10 4 2 2" />
                                    </svg>
                                </button>
                            </span>
                            <span class="block text-[11px] text-stone-500 tabular-nums" :title="row.category.ratio ? '更新日時' : undefined" :data-testid="`ratio-updated-${row.category.id}`">{{
                                row.category.ratio ? formatDateTime(row.category.ratio.changed_at) : "比率が未登録"
                            }}</span>
                        </template>
                    </th>
                    <td v-for="(month, index) in months" :key="month" class="px-1 py-2.5 align-top">
                        <input
                            v-model="drafts[row.category.id]![index]"
                            type="number"
                            min="0"
                            step="0.01"
                            inputmode="decimal"
                            class="h-8 w-full min-w-14 rounded-md border bg-white px-1.5 text-right text-[13px] tabular-nums focus:border-stone-900 focus:outline-none"
                            :class="row.values[index] === null ? 'border-red-400' : 'border-stone-300'"
                            :aria-label="`${row.category.name}の${monthLabel(month)}の比率（%）`"
                        />
                    </td>
                    <td class="px-3 py-2.5 text-right align-top whitespace-nowrap tabular-nums" :data-testid="`ratio-total-${row.category.id}`">
                        <span class="inline-block pt-1.5 font-semibold" :class="row.total === 10000 ? 'text-stone-900' : 'text-red-700'">{{ formatRatio(row.total) }}%</span>
                    </td>
                    <td class="px-4 py-2.5 text-right align-top whitespace-nowrap">
                        <button
                            type="button"
                            class="h-8 rounded-lg bg-stone-900 px-3 text-xs font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-30"
                            :disabled="!row.canSave || savingId !== null"
                            :data-testid="`ratio-save-${row.category.id}`"
                            @click="save(row.category, row.values)"
                        >
                            {{ savingId === row.category.id ? "保存中…" : "保存" }}
                        </button>
                        <span v-if="errors[row.category.id]" class="mt-1 block text-[11px] text-red-700">{{ errors[row.category.id] }}</span>
                        <span v-else-if="row.isChanged && row.total !== 10000" class="mt-1 block text-[11px] text-red-700">合計を100.00%にしてください</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="flex flex-wrap items-center gap-2 border-t border-stone-200 px-4 py-3">
            <button
                v-if="!isAdding"
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 text-xs font-medium text-stone-900 hover:bg-stone-100"
                data-testid="add-category"
                @click="isAdding = true"
            >
                <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10" /></svg>
                区分を追加
            </button>
            <form v-else class="flex flex-wrap items-center gap-2" @submit.prevent="add">
                <label class="sr-only" for="new-category-name">追加する区分の名前</label>
                <input
                    id="new-category-name"
                    v-model="addName"
                    type="text"
                    maxlength="50"
                    placeholder="区分の名前（例：ブルーライトカット）"
                    class="h-8 w-72 rounded-md border border-stone-300 bg-white px-2 text-[13px] text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none"
                    data-testid="new-category-name"
                    @keydown.esc.stop="isAdding = false"
                />
                <button
                    type="submit"
                    class="h-8 rounded-lg bg-stone-900 px-3 text-xs font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="addName.trim() === '' || isCreating"
                    data-testid="add-category-save"
                >
                    追加
                </button>
                <button type="button" class="h-8 rounded-lg px-2 text-xs text-stone-600 hover:bg-stone-100" @click="isAdding = false">取消</button>
                <span v-if="addError" class="text-xs text-red-700">{{ addError }}</span>
            </form>
            <span class="text-[11px] text-stone-500">足した区分は、比率を入れて保存すると品番に選べるようになります（比率が無いうちは月に分けられません）。</span>
        </div>
    </div>
</template>
