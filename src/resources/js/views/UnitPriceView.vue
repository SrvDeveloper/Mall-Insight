<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef } from "vue";
import { ApiError } from "@/api/client";
import { fetchItemUnitPrices, saveItemUnitPrices } from "@/api/itemUnitPrices";
import { formatDateTime, formatQuantity, toQuantity } from "@/components/salesTarget/format";
import type { ItemUnitPriceResponse, ItemUnitPriceRow } from "@/types/api";

/**
 * 単価（バックログ B-110、決定記録 K-062・K-063）。品番ごとに、全体・Amazon・BOSSの単価（税込）を登録する。
 * 全体の単価はモールを分けない金額（販売目標の売上金額の概算など）に、Amazon・BOSSの単価はモール別の金額に使う。
 * 登録の無い単価は、直近12か月の販売実績の平均単価を使うため、平均単価を並べて見せる。
 * 見た目は在庫推移の画面（K-047）とそろえる。行の「編集」で開くダイアログで、その品番の3つの単価をまとめて直す。
 */

type Filter = "all" | "target" | "registered" | "unregistered";
type PriceKey = "unit_price" | "amazon_unit_price" | "boss_unit_price";

const COLUMNS: { key: PriceKey; actual: "all" | "amazon" | "boss"; label: string; note: string }[] = [
    { key: "unit_price", actual: "all", label: "全体", note: "モールを分けない金額に使う" },
    { key: "amazon_unit_price", actual: "amazon", label: "Amazon", note: "Amazonの金額に使う" },
    { key: "boss_unit_price", actual: "boss", label: "BOSS", note: "楽天・Yahoo!・au PAYの金額に使う" },
];

const result = shallowRef<ItemUnitPriceResponse | null>(null);
const hasError = ref(false);
const isLoading = ref(false);
const filter = ref<Filter>("all");
const keyword = ref("");
const message = ref<string | null>(null);

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchItemUnitPrices();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);

const rows = computed(() => result.value?.data ?? []);
const isRegistered = (row: ItemUnitPriceRow): boolean => row.registered !== null;

const filterOptions = computed(() => [
    { value: "all" as Filter, label: "すべて", count: rows.value.length },
    { value: "target" as Filter, label: "対象品番", count: rows.value.filter((row) => row.is_target_item).length },
    { value: "registered" as Filter, label: "登録済み", count: rows.value.filter(isRegistered).length },
    { value: "unregistered" as Filter, label: "未登録", count: rows.value.filter((row) => !isRegistered(row)).length },
]);

const visibleRows = computed(() => {
    const word = keyword.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        const matchesFilter = { all: true, target: row.is_target_item, registered: isRegistered(row), unregistered: !isRegistered(row) }[filter.value];
        return matchesFilter && (word === "" || row.item_no.toLowerCase().includes(word) || row.brand.toLowerCase().includes(word));
    });
});

const facts = computed(() => {
    const targets = rows.value.filter((row) => row.is_target_item);
    const meta = result.value?.meta;
    return [
        { key: "registered", label: "単価を登録した品番", value: `${rows.value.filter(isRegistered).length} / ${rows.value.length}品番`, note: "登録の無い品番は平均単価を使います" },
        {
            key: "target",
            label: "対象品番",
            value: `${targets.filter(isRegistered).length} / ${targets.length}品番に登録`,
            note: "需要予測・在庫推移・販売目標の対象",
        },
        {
            key: "period",
            label: "平均単価の期間",
            value: meta ? `${meta.sales_from.replaceAll("-", "/")} 〜 ${meta.sales_to.replaceAll("-", "/")}` : "—",
            note: "販売金額（税込）÷販売数",
        },
    ];
});

// 単価の編集（ダイアログ）
const editing = shallowRef<ItemUnitPriceRow | null>(null);
const draft = ref<Record<PriceKey, number | string>>({ unit_price: "", amazon_unit_price: "", boss_unit_price: "" });
const isSaving = ref(false);
const errors = ref<Record<string, string[]>>({});
const firstInput = useTemplateRef<HTMLInputElement[]>("firstInput");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeDialog();
    }
}

async function openDialog(row: ItemUnitPriceRow): Promise<void> {
    editing.value = row;
    draft.value = {
        unit_price: row.registered?.unit_price ?? "",
        amazon_unit_price: row.registered?.amazon_unit_price ?? "",
        boss_unit_price: row.registered?.boss_unit_price ?? "",
    };
    errors.value = {};
    message.value = null;
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    firstInput.value?.[0]?.focus();
}

function closeDialog(): void {
    editing.value = null;
    document.removeEventListener("keydown", onKeydown);
}

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

async function save(): Promise<void> {
    const row = editing.value;
    if (!row || isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = {};
    try {
        await saveItemUnitPrices(row.item_no, {
            unit_price: toQuantity(draft.value.unit_price),
            amazon_unit_price: toQuantity(draft.value.amazon_unit_price),
            boss_unit_price: toQuantity(draft.value.boss_unit_price),
        });
        closeDialog();
        message.value = `${row.item_no} の単価を保存しました。`;
        await load();
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = error.errors;
        }
    } finally {
        isSaving.value = false;
    }
}

const errorOf = (key: PriceKey): string | null => errors.value[key]?.[0] ?? null;
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">商品 / 単価</p>
            <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">単価</h1>
            <p class="max-w-3xl text-[13px] text-stone-600">
                品番ごとの単価（税込）を登録します。全体の単価はモールを分けない金額（販売目標の売上金額の概算など）に、Amazon・BOSSの単価はモール別の金額に使います。登録の無い単価は、直近12か月の平均単価を使います。
            </p>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">単価を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>

        <div v-else-if="!result" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <template v-else>
            <section class="grid grid-cols-1 rounded-xl border border-stone-200 bg-white sm:grid-cols-3" aria-label="単価のまとめ" data-testid="price-summary">
                <div
                    v-for="(fact, index) in facts"
                    :key="fact.key"
                    class="flex flex-col gap-1 px-5 py-4"
                    :class="index > 0 ? 'border-stone-200 max-sm:border-t sm:border-l' : ''"
                    :data-testid="`summary-${fact.key}`"
                >
                    <span class="text-[11px] tracking-wide text-stone-500">{{ fact.label }}</span>
                    <span class="text-base font-semibold text-stone-900 tabular-nums">{{ fact.value }}</span>
                    <span class="text-xs text-stone-400">{{ fact.note }}</span>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                        <button
                            v-for="option in filterOptions"
                            :key="option.value"
                            type="button"
                            class="h-8 rounded-full border bg-white px-3 text-[13px]"
                            :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                            :aria-pressed="filter === option.value"
                            :data-testid="`filter-${option.value}`"
                            @click="filter = option.value"
                        >
                            {{ option.label }} <span class="text-stone-500 tabular-nums">{{ option.count }}</span>
                        </button>
                    </div>
                    <label class="flex h-9 w-full items-center gap-2 rounded-lg border border-stone-200 px-3 text-stone-400 focus-within:border-stone-900 lg:w-60">
                        <svg class="size-[15px] shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="m10.5 10.5 3 3" />
                        </svg>
                        <span class="sr-only">品番・ブランドで絞り込み</span>
                        <input
                            v-model="keyword"
                            type="search"
                            placeholder="品番・ブランドで絞り込み"
                            class="w-full bg-transparent text-[13px] text-stone-900 outline-none placeholder:text-stone-400"
                        />
                    </label>
                </div>

                <div v-if="visibleRows.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">条件に一致する品番はありません。</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] border-separate border-spacing-0 text-left text-[13px]" data-testid="price-table">
                        <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                            <tr>
                                <th scope="col" class="px-4 py-2.5">品番</th>
                                <th v-for="column in COLUMNS" :key="column.key" scope="col" class="w-40 px-3 py-2.5 text-right" :title="column.note">{{ column.label }}（円）</th>
                                <th scope="col" class="px-3 py-2.5 whitespace-nowrap">更新</th>
                                <th scope="col" class="px-4 py-2.5"><span class="sr-only">操作</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in visibleRows" :key="row.item_no" class="hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="price-row">
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <span class="font-mono text-sm font-medium text-stone-900">{{ row.item_no }}</span>
                                    <span class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ row.brand }}・{{ row.category }}</span>
                                    <span v-if="row.is_target_item" class="ml-1.5 rounded-full bg-stone-900 px-2 py-px text-[11px] font-semibold text-white">対象</span>
                                    <span v-if="row.status !== 'active'" class="ml-1.5 rounded-full bg-stone-100 px-2 py-px text-[11px] text-stone-500">{{ row.status_label }}</span>
                                </td>
                                <td v-for="column in COLUMNS" :key="column.key" class="px-3 py-2 text-right align-top tabular-nums">
                                    <span
                                        class="block text-sm"
                                        :class="row.registered?.[column.key] != null ? 'font-semibold text-stone-900' : 'text-stone-300'"
                                        :data-testid="`registered-${column.actual}`"
                                        >{{ row.registered?.[column.key] != null ? formatQuantity(row.registered[column.key]!) : "—" }}</span
                                    >
                                    <span class="mt-0.5 block text-[11px] text-stone-500" :data-testid="`actual-${column.actual}`">
                                        <template v-if="row.actual[column.actual].unit_price !== null"
                                            >平均 {{ formatQuantity(row.actual[column.actual].unit_price!) }}（{{ formatQuantity(row.actual[column.actual].quantity) }}個）</template
                                        >
                                        <template v-else>販売実績なし</template>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 align-top text-[11px] whitespace-nowrap text-stone-500 tabular-nums" data-testid="changed">
                                    <template v-if="row.registered">
                                        {{ formatDateTime(row.registered.changed_at) }}
                                        <span v-if="row.registered.changed_by" class="block">{{ row.registered.changed_by }}</span>
                                    </template>
                                    <span v-else class="text-stone-300">—</span>
                                </td>
                                <td class="px-4 py-2 text-right align-top whitespace-nowrap">
                                    <button
                                        type="button"
                                        class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-200 hover:text-stone-900"
                                        :data-testid="`edit-price-${row.item_no}`"
                                        @click="openDialog(row)"
                                    >
                                        {{ row.registered ? "編集" : "登録" }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeDialog">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="price-dialog-title"
                class="flex max-h-full w-full max-w-xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5"
                data-testid="price-dialog"
            >
                <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 id="price-dialog-title" class="text-base font-bold text-stone-900">
                            <span class="font-mono">{{ editing.item_no }}</span> の単価を{{ editing.registered ? "編集" : "登録" }}
                        </h2>
                        <p class="text-xs text-stone-500">{{ editing.brand }}・{{ editing.category }}。税込の単価を入れます。空欄の単価は直近12か月の平均単価を使います。</p>
                    </div>
                    <button
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeDialog"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <form class="flex flex-col gap-5 overflow-y-auto px-5 py-5" @submit.prevent="save">
                    <div class="flex flex-col gap-4">
                        <label v-for="(column, index) in COLUMNS" :key="column.key" class="grid grid-cols-[1fr_10rem] items-start gap-x-4 gap-y-1">
                            <span class="flex flex-col gap-0.5 pt-2">
                                <span class="text-[13px] font-semibold text-stone-800">{{ column.label }}</span>
                                <span class="text-[11px] text-stone-500">{{ column.note }}</span>
                            </span>
                            <span class="flex flex-col gap-1">
                                <span class="flex items-center gap-1.5">
                                    <input
                                        :ref="index === 0 ? 'firstInput' : undefined"
                                        v-model="draft[column.key]"
                                        type="number"
                                        min="1"
                                        inputmode="numeric"
                                        :placeholder="editing.actual[column.actual].unit_price === null ? '—' : formatQuantity(editing.actual[column.actual].unit_price!)"
                                        class="h-10 w-full rounded-lg border bg-white px-3 text-right text-sm tabular-nums placeholder:text-stone-300 focus:border-stone-900 focus:outline-none"
                                        :class="errorOf(column.key) ? 'border-red-400' : 'border-stone-300'"
                                        :aria-label="`${editing.item_no} の${column.label}の単価`"
                                    />
                                    <span class="text-xs text-stone-500">円</span>
                                </span>
                                <span class="text-right text-[11px] text-stone-500 tabular-nums" :data-testid="`dialog-actual-${column.actual}`">
                                    <template v-if="editing.actual[column.actual].unit_price !== null"
                                        >平均 {{ formatQuantity(editing.actual[column.actual].unit_price!) }}（{{ formatQuantity(editing.actual[column.actual].quantity) }}個）</template
                                    >
                                    <template v-else>販売実績なし</template>
                                </span>
                                <span v-if="errorOf(column.key)" class="text-xs text-red-700">{{ errorOf(column.key) }}</span>
                            </span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-stone-200 pt-4">
                        <button type="button" class="h-10 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="closeDialog">
                            キャンセル
                        </button>
                        <button
                            type="submit"
                            class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="isSaving"
                            data-testid="save-price"
                        >
                            {{ isSaving ? "保存中…" : "保存" }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
