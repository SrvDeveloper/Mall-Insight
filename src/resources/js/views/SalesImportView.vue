<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from "vue";
import { fetchSalesImport, fetchSalesImports, uploadSalesImport } from "@/api/salesImports";
import { ApiError } from "@/api/client";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import type { Paginated, SalesImport } from "@/types/api";

/**
 * 販売実績の取込（バックログ B-004、決定記録 K-022）。BOSS受注実績のCSVを取り込み、結果とエラー・警告を確認する。
 */

const fileInput = useTemplateRef<HTMLInputElement>("fileInput");
const selectedFile = ref<File | null>(null);
const isUploading = ref(false);
const uploadError = ref<string | null>(null);

const current = ref<SalesImport | null>(null);
const history = ref<Paginated<SalesImport> | null>(null);
const historyPage = ref(1);
const hasHistoryError = ref(false);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));
const formatDate = (value: string): string => value.replaceAll("-", "/");

async function loadHistory(): Promise<void> {
    try {
        history.value = await fetchSalesImports(historyPage.value);
        hasHistoryError.value = false;
    } catch {
        hasHistoryError.value = true;
    }
}

function onFileChange(event: Event): void {
    selectedFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    uploadError.value = null;
}

async function upload(): Promise<void> {
    if (!selectedFile.value || isUploading.value) {
        return;
    }
    isUploading.value = true;
    uploadError.value = null;
    try {
        current.value = await uploadSalesImport(selectedFile.value);
        selectedFile.value = null;
        if (fileInput.value) {
            fileInput.value.value = "";
        }
        historyPage.value = 1;
        await loadHistory();
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            uploadError.value = Object.values(error.errors).flat()[0] ?? error.message;
        }
    } finally {
        isUploading.value = false;
    }
}

async function showImport(id: number): Promise<void> {
    try {
        current.value = await fetchSalesImport(id);
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

function goToHistoryPage(page: number): void {
    historyPage.value = page;
    void loadHistory();
}

onMounted(loadHistory);

const sortedIssues = computed(() => [...(current.value?.issues ?? [])].sort((a, b) => (a.row_number ?? 0) - (b.row_number ?? 0)));
const errorCount = computed(() => sortedIssues.value.filter((issue) => issue.level === "error").length);
const warningCount = computed(() => sortedIssues.value.filter((issue) => issue.level === "warning").length);

const resultCounts = computed(() => {
    const result = current.value;
    if (!result || result.status === "failed") {
        return [];
    }
    return [
        { label: "データ行", value: result.row_count },
        { label: "新しく登録", value: result.created_line_count },
        { label: "上書き", value: result.updated_line_count },
        { label: "重複で対象外", value: result.skipped_line_count },
        { label: "エラーで未登録", value: result.error_row_count, tone: (result.error_row_count ?? 0) > 0 ? "text-red-700" : "" },
        { label: "CrossWalkerに無いSKU", value: result.created_sku_count },
    ];
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-bold tracking-tight text-stone-900">販売実績の取込</h1>
            <p class="text-sm text-stone-500">
                BOSSの受注実績一覧から出力したCSV（「完了」の受注実績）を取り込みます。楽天市場・Yahoo!ショッピング・au PAY
                マーケットの販売実績が対象です。同じ注文を取り込み直すと上書きされ、二重にはなりません。
            </p>
        </header>

        <form class="flex flex-col gap-3 rounded-lg border border-stone-200 bg-white p-4 sm:flex-row sm:items-center" @submit.prevent="upload">
            <label for="sales-file" class="text-sm font-semibold text-stone-700">BOSS受注実績のCSV</label>
            <input
                id="sales-file"
                ref="fileInput"
                type="file"
                accept=".csv"
                class="min-w-0 flex-1 text-sm text-stone-700 file:mr-3 file:rounded-md file:border file:border-stone-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-stone-700 hover:file:bg-stone-100"
                @change="onFileChange"
            />
            <button
                type="submit"
                class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!selectedFile || isUploading"
            >
                {{ isUploading ? "取込中…" : "取り込む" }}
            </button>
        </form>
        <p v-if="uploadError" class="-mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ uploadError }}</p>

        <section v-if="current" class="flex flex-col gap-4 rounded-lg border border-stone-200 bg-white p-4" aria-label="取込の結果">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 class="text-base font-semibold text-stone-900">{{ current.file_name }}</h2>
                <StatusBadge
                    :tone="current.status === 'failed' ? 'danger' : errorCount > 0 ? 'warning' : 'positive'"
                    :label="current.status === 'failed' ? '取り込めませんでした' : errorCount > 0 ? 'エラーあり' : '取り込みました'"
                />
                <span class="text-sm text-stone-500 tabular-nums">{{ formatDateTime(current.finished_at) }}</span>
            </div>

            <p v-if="current.status === 'failed'" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ current.error_message }}</p>

            <template v-else>
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="count in resultCounts" :key="count.label" class="flex flex-col gap-0.5 rounded-md bg-stone-50 px-3 py-2">
                        <dt class="text-xs text-stone-500">{{ count.label }}</dt>
                        <dd class="text-lg font-semibold text-stone-900 tabular-nums" :class="count.tone">{{ count.value ?? "—" }}</dd>
                    </div>
                </dl>
                <p v-if="current.sales_date_from && current.sales_date_to" class="text-sm text-stone-600 tabular-nums">
                    販売日 {{ formatDate(current.sales_date_from) }} 〜 {{ formatDate(current.sales_date_to) }} の販売実績を登録しました。
                </p>

                <div v-if="sortedIssues.length > 0" class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold text-stone-700">エラー {{ errorCount }}件・警告 {{ warningCount }}件</h3>
                    <div class="overflow-x-auto rounded-md border border-stone-200">
                        <table class="w-full text-left text-[13px]">
                            <thead class="border-b border-stone-200 bg-stone-50 text-xs text-stone-500">
                                <tr>
                                    <th scope="col" class="px-3 py-2 font-medium">区分</th>
                                    <th scope="col" class="px-3 py-2 text-right font-medium">行</th>
                                    <th scope="col" class="px-3 py-2 font-medium">注文ID</th>
                                    <th scope="col" class="px-3 py-2 font-medium">SKUコード</th>
                                    <th scope="col" class="px-3 py-2 font-medium">内容</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                <tr v-for="issue in sortedIssues" :key="issue.id">
                                    <td class="px-3 py-2"><StatusBadge :tone="issue.level === 'error' ? 'danger' : 'warning'" :label="issue.level_label" /></td>
                                    <td class="px-3 py-2 text-right text-stone-700 tabular-nums">{{ issue.row_number ?? "—" }}</td>
                                    <td class="px-3 py-2 font-mono whitespace-nowrap text-stone-700">{{ issue.source_order_id ?? "—" }}</td>
                                    <td class="px-3 py-2 font-mono whitespace-nowrap text-stone-700">{{ issue.sku_code ?? "—" }}</td>
                                    <td class="px-3 py-2 text-stone-800">{{ issue.message }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p v-else class="text-sm text-stone-500">エラー・警告はありません。</p>
            </template>
        </section>

        <section class="overflow-hidden rounded-lg border border-stone-200 bg-white">
            <h2 class="border-b border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-700">取込の履歴</h2>
            <p v-if="hasHistoryError" class="px-4 py-8 text-center text-sm text-stone-600">履歴を表示できませんでした。</p>
            <p v-else-if="!history" class="px-4 py-8 text-center text-sm text-stone-500">読み込み中…</p>
            <p v-else-if="history.data.length === 0" class="px-4 py-8 text-center text-sm text-stone-500">まだ取り込んでいません。</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">取込日時</th>
                            <th scope="col" class="px-4 py-2.5">ファイル</th>
                            <th scope="col" class="px-4 py-2.5">結果</th>
                            <th scope="col" class="px-4 py-2.5 text-right">新規</th>
                            <th scope="col" class="px-4 py-2.5 text-right">上書き</th>
                            <th scope="col" class="px-4 py-2.5 text-right">エラー</th>
                            <th scope="col" class="px-4 py-2.5 text-right">警告</th>
                            <th scope="col" class="px-4 py-2.5"><span class="sr-only">詳細</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <tr v-for="item in history.data" :key="item.id" :class="current?.id === item.id ? 'bg-stone-50' : ''">
                            <td class="px-4 py-2.5 whitespace-nowrap text-stone-600 tabular-nums">{{ formatDateTime(item.finished_at) }}</td>
                            <td class="px-4 py-2.5 text-stone-900">{{ item.file_name }}</td>
                            <td class="px-4 py-2.5">
                                <StatusBadge v-if="item.status === 'failed'" tone="danger" label="取り込めませんでした" />
                                <StatusBadge v-else-if="(item.error_row_count ?? 0) > 0" tone="warning" label="エラーあり" />
                                <StatusBadge v-else tone="positive" label="取り込みました" />
                            </td>
                            <td class="px-4 py-2.5 text-right text-stone-700 tabular-nums">{{ item.created_line_count ?? "—" }}</td>
                            <td class="px-4 py-2.5 text-right text-stone-700 tabular-nums">{{ item.updated_line_count ?? "—" }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="(item.error_row_count ?? 0) > 0 ? 'font-semibold text-red-700' : 'text-stone-700'">
                                {{ item.error_row_count ?? "—" }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-stone-700 tabular-nums">{{ item.warning_count ?? "—" }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-100" @click="showImport(item.id)">詳細</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer v-if="history && history.meta.last_page > 1" class="flex items-center justify-end gap-2 border-t border-stone-200 px-4 py-3 text-sm">
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:opacity-40"
                    :disabled="history.meta.current_page <= 1"
                    @click="goToHistoryPage(history.meta.current_page - 1)"
                >
                    新しい履歴へ
                </button>
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:opacity-40"
                    :disabled="history.meta.current_page >= history.meta.last_page"
                    @click="goToHistoryPage(history.meta.current_page + 1)"
                >
                    古い履歴へ
                </button>
            </footer>
        </section>
    </div>
</template>
