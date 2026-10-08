<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from "vue";
import { fetchSalesImport, fetchSalesImports, uploadSalesImport } from "@/api/salesImports";
import { ApiError } from "@/api/client";
import type { Paginated, SalesImport } from "@/types/api";

/**
 * 販売実績の取込（バックログ B-004・B-101、決定記録 K-022・K-040）。BOSS受注実績のCSVと Amazon全注文レポートを取り込み、
 * 結果とエラー・警告を確認する。取込元はファイルの中身からサーバーが判定する。
 * Amazon のレポートは1か月単位でしか出力できないため、複数のファイルをまとめて選び、1つずつ順に取り込めるようにする。
 * ファイルは選ぶか、枠にドロップする。見た目は在庫推移の画面（K-047）とそろえる。
 */

/** まとめて取り込んだファイル1つ分の結果。サーバーが受け付けなかったファイルは import が null で、理由を持つ。 */
interface BatchResult {
    fileName: string;
    import: SalesImport | null;
    error: string | null;
}

const fileInput = useTemplateRef<HTMLInputElement>("fileInput");
const selectedFiles = ref<File[]>([]);
const isUploading = ref(false);
const uploadingIndex = ref(0);
const batchResults = ref<BatchResult[]>([]);

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

/** 取り込めるファイルの拡張子（input の accept と同じ）。 */
const ACCEPTED_EXTENSIONS = [".csv", ".txt", ".tsv"];

function selectFiles(files: File[]): void {
    // ファイル名の順（「2025年10月」「2025年11月」…）に取り込む
    selectedFiles.value = files.sort((a, b) => a.name.localeCompare(b.name, "ja", { numeric: true }));
    batchResults.value = [];
}

function onFileChange(event: Event): void {
    selectFiles(Array.from((event.target as HTMLInputElement).files ?? []));
}

const isDragging = ref(false);

function onDrop(event: DragEvent): void {
    isDragging.value = false;
    if (isUploading.value) {
        return;
    }
    const files = Array.from(event.dataTransfer?.files ?? []).filter((file) => ACCEPTED_EXTENSIONS.some((extension) => file.name.toLowerCase().endsWith(extension)));
    if (files.length > 0) {
        selectFiles(files);
    }
}

const formatSize = (bytes: number): string => (bytes >= 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

async function upload(): Promise<void> {
    if (selectedFiles.value.length === 0 || isUploading.value) {
        return;
    }
    isUploading.value = true;
    batchResults.value = [];
    try {
        for (const [index, file] of selectedFiles.value.entries()) {
            uploadingIndex.value = index;
            try {
                const result = await uploadSalesImport(file);
                batchResults.value.push({ fileName: file.name, import: result, error: null });
                current.value = result;
            } catch (error) {
                const message = error instanceof ApiError && error.isValidationError ? (Object.values(error.errors).flat()[0] ?? error.message) : "送信できませんでした。";
                batchResults.value.push({ fileName: file.name, import: null, error: message });
            }
        }
        selectedFiles.value = [];
        if (fileInput.value) {
            fileInput.value.value = "";
        }
        historyPage.value = 1;
        await loadHistory();
    } finally {
        isUploading.value = false;
    }
}

type ResultTone = "danger" | "warning" | "positive";

/** 結果の札の色（取り込めなかった＝赤、エラーあり＝橙、取り込んだ＝枠だけ）。 */
function pillClass(tone: ResultTone): string {
    return { danger: "bg-red-100 font-semibold text-red-700", warning: "bg-amber-100 font-semibold text-amber-800", positive: "border border-stone-200 text-stone-600" }[tone];
}

function importTone(item: SalesImport): ResultTone {
    if (item.status === "failed") {
        return "danger";
    }
    return (item.error_row_count ?? 0) > 0 ? "warning" : "positive";
}

const TONE_LABELS: Record<ResultTone, string> = { danger: "取り込めませんでした", warning: "エラーあり", positive: "取り込みました" };

function batchTone(result: BatchResult): ResultTone {
    if (!result.import || result.import.status === "failed") {
        return "danger";
    }
    return (result.import.error_row_count ?? 0) > 0 ? "warning" : "positive";
}

function batchLabel(result: BatchResult): string {
    return TONE_LABELS[batchTone(result)];
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
        { label: result.source === "amazon" ? "未出荷などで対象外" : "重複で対象外", value: result.skipped_line_count },
        { label: "エラーで未登録", value: result.error_row_count, tone: (result.error_row_count ?? 0) > 0 ? "text-red-700" : "" },
        { label: "CrossWalkerに無いSKU", value: result.created_sku_count },
    ];
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">取込 / 販売実績の取込</p>
            <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">販売実績の取込</h1>
            <p class="max-w-3xl text-[13px] text-stone-600">
                BOSSの受注実績一覧から出力したCSV（楽天市場・Yahoo!ショッピング・au PAY マーケット）と、Amazonの全注文レポート（テキスト）を取り込みます。
                どちらのファイルかは自動で判定します。複数のファイルをまとめて選べます。同じ注文を取り込み直すと上書きされ、二重にはなりません。
            </p>
        </header>

        <form class="flex flex-col gap-3 rounded-xl border border-stone-200 bg-white p-4" @submit.prevent="upload">
            <label
                for="sales-file"
                class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors"
                :class="[isDragging ? 'border-stone-900 bg-stone-50' : 'border-stone-300 hover:border-stone-400 hover:bg-stone-50', isUploading ? 'pointer-events-none opacity-60' : '']"
                data-testid="drop-zone"
                @dragenter.prevent="isDragging = true"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="onDrop"
            >
                <svg class="size-7 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 15V4m0 0-4 4m4-4 4 4" />
                    <path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                </svg>
                <span class="text-sm font-semibold text-stone-900">販売実績のファイルを選ぶ</span>
                <span class="text-xs text-stone-500">またはここにドロップ（CSV・テキスト、複数可）</span>
                <input id="sales-file" ref="fileInput" type="file" accept=".csv,.txt,.tsv" multiple class="sr-only" :disabled="isUploading" @change="onFileChange" />
            </label>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <ul v-if="selectedFiles.length > 0" class="flex min-w-0 flex-wrap gap-1.5" aria-label="選んだファイル" data-testid="selected-files">
                    <li
                        v-for="(file, index) in selectedFiles"
                        :key="file.name"
                        class="inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
                        :class="isUploading && index === uploadingIndex ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-700'"
                    >
                        <span class="truncate">{{ file.name }}</span>
                        <span class="shrink-0 text-[11px] text-stone-400 tabular-nums">{{ formatSize(file.size) }}</span>
                    </li>
                </ul>
                <p v-else class="text-xs text-stone-400">まだファイルを選んでいません。</p>
                <button
                    type="submit"
                    class="h-10 shrink-0 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold whitespace-nowrap text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="selectedFiles.length === 0 || isUploading"
                >
                    {{ isUploading ? `取込中…（${uploadingIndex + 1}/${selectedFiles.length}）` : selectedFiles.length > 1 ? `${selectedFiles.length}ファイルを取り込む` : "取り込む" }}
                </button>
            </div>
            <div v-if="isUploading" class="flex flex-col gap-1.5" role="status">
                <span class="h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                    <span class="block h-full rounded-full bg-stone-900 transition-[width]" :style="{ width: `${(uploadingIndex / selectedFiles.length) * 100}%` }" />
                </span>
                <p class="text-xs text-stone-600">{{ selectedFiles[uploadingIndex]?.name }} を取り込んでいます…</p>
            </div>
        </form>

        <section v-if="batchResults.length > 1" class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="まとめて取り込んだ結果">
            <h2 class="border-b border-stone-200 px-4 py-3.5 text-sm font-semibold text-stone-900">まとめて取り込んだ結果（{{ batchResults.length }}ファイル）</h2>
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">ファイル</th>
                            <th scope="col" class="px-3 py-2.5">取込元</th>
                            <th scope="col" class="px-3 py-2.5">結果</th>
                            <th scope="col" class="px-3 py-2.5 text-right">新規</th>
                            <th scope="col" class="px-3 py-2.5 text-right">上書き</th>
                            <th scope="col" class="px-3 py-2.5 text-right">エラー</th>
                            <th scope="col" class="px-4 py-2.5"><span class="sr-only">詳細</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(result, index) in batchResults" :key="index" class="h-11 hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100" data-testid="batch-row">
                            <td class="px-4 text-stone-900">{{ result.fileName }}</td>
                            <td class="px-3 text-stone-700">{{ result.import?.source_label ?? "—" }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap" :class="pillClass(batchTone(result))">{{ batchLabel(result) }}</span>
                                <span v-if="result.error" class="ml-2 text-xs text-red-700">{{ result.error }}</span>
                            </td>
                            <td class="px-3 text-right text-stone-700 tabular-nums">{{ result.import?.created_line_count ?? "—" }}</td>
                            <td class="px-3 text-right text-stone-700 tabular-nums">{{ result.import?.updated_line_count ?? "—" }}</td>
                            <td class="px-3 text-right tabular-nums" :class="(result.import?.error_row_count ?? 0) > 0 ? 'font-semibold text-red-700' : 'text-stone-700'">
                                {{ result.import?.error_row_count ?? "—" }}
                            </td>
                            <td class="px-4 text-right">
                                <button
                                    v-if="result.import"
                                    type="button"
                                    class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                                    @click="showImport(result.import.id)"
                                >
                                    詳細
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p v-else-if="batchResults.length === 1 && batchResults[0]?.error" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ batchResults[0].error }}</p>

        <section v-if="current" class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="取込の結果">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-stone-200 px-4 py-3.5">
                <h2 class="text-sm font-semibold text-stone-900">{{ current.file_name }}</h2>
                <span class="rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ current.source_label }}</span>
                <span class="rounded-full px-2 py-0.5 text-[11px]" :class="pillClass(current.status === 'failed' ? 'danger' : errorCount > 0 ? 'warning' : 'positive')">{{
                    current.status === "failed" ? "取り込めませんでした" : errorCount > 0 ? "エラーあり" : "取り込みました"
                }}</span>
                <span class="text-xs text-stone-500 tabular-nums">{{ formatDateTime(current.finished_at) }}</span>
            </div>

            <p v-if="current.status === 'failed'" class="m-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ current.error_message }}</p>

            <template v-else>
                <dl class="grid grid-cols-2 border-b border-stone-200 sm:grid-cols-3 lg:grid-cols-6">
                    <div
                        v-for="(count, index) in resultCounts"
                        :key="count.label"
                        class="flex flex-col gap-1 border-stone-200 px-4 py-3"
                        :class="
                            index > 0 ? 'max-sm:even:border-l max-sm:[&:nth-child(n+3)]:border-t sm:max-lg:[&:not(:nth-child(3n+1))]:border-l sm:max-lg:[&:nth-child(n+4)]:border-t lg:border-l' : ''
                        "
                    >
                        <dt class="text-[11px] tracking-wide text-stone-500">{{ count.label }}</dt>
                        <dd class="text-base font-semibold text-stone-900 tabular-nums" :class="count.tone">{{ count.value ?? "—" }}</dd>
                    </div>
                </dl>
                <div class="flex flex-col gap-3 px-4 py-3.5">
                    <p v-if="current.sales_date_from && current.sales_date_to" class="text-[13px] text-stone-600 tabular-nums">
                        販売日 {{ formatDate(current.sales_date_from) }} 〜 {{ formatDate(current.sales_date_to) }} の販売実績を登録しました。
                    </p>

                    <div v-if="sortedIssues.length > 0" class="flex flex-col gap-2">
                        <h3 class="text-xs font-medium text-stone-700">エラー {{ errorCount }}件・警告 {{ warningCount }}件</h3>
                        <div class="max-h-96 overflow-auto rounded-lg border border-stone-200">
                            <table class="w-full border-separate border-spacing-0 text-left text-[13px]">
                                <thead class="text-xs font-medium text-stone-500 [&_th]:sticky [&_th]:top-0 [&_th]:border-b [&_th]:border-stone-200 [&_th]:bg-white">
                                    <tr>
                                        <th scope="col" class="px-3 py-2">区分</th>
                                        <th scope="col" class="px-3 py-2 text-right">行</th>
                                        <th scope="col" class="px-3 py-2">注文ID</th>
                                        <th scope="col" class="px-3 py-2">SKUコード</th>
                                        <th scope="col" class="px-3 py-2">内容</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(issue, index) in sortedIssues" :key="issue.id" :class="index > 0 ? '[&>*]:border-t [&>*]:border-stone-100' : ''">
                                        <td class="px-3 py-2">
                                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="pillClass(issue.level === 'error' ? 'danger' : 'warning')">{{ issue.level_label }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-right text-stone-700 tabular-nums">{{ issue.row_number ?? "—" }}</td>
                                        <td class="px-3 py-2 font-mono text-[12.5px] whitespace-nowrap text-stone-700">{{ issue.source_order_id ?? "—" }}</td>
                                        <td class="px-3 py-2 font-mono text-[12.5px] whitespace-nowrap text-stone-700">{{ issue.sku_code ?? "—" }}</td>
                                        <td class="px-3 py-2 text-stone-800">{{ issue.message }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p v-else class="text-[13px] text-stone-500">エラー・警告はありません。</p>
                </div>
            </template>
        </section>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <h2 class="border-b border-stone-200 px-4 py-3.5 text-sm font-semibold text-stone-900">取込の履歴</h2>
            <p v-if="hasHistoryError" class="px-4 py-8 text-center text-sm text-stone-600">履歴を表示できませんでした。</p>
            <p v-else-if="!history" class="px-4 py-8 text-center text-sm text-stone-500">読み込み中…</p>
            <p v-else-if="history.data.length === 0" class="px-4 py-8 text-center text-sm text-stone-500">まだ取り込んでいません。</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[56rem] border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">取込日時</th>
                            <th scope="col" class="px-3 py-2.5">ファイル</th>
                            <th scope="col" class="px-3 py-2.5">取込元</th>
                            <th scope="col" class="px-3 py-2.5">結果</th>
                            <th scope="col" class="px-3 py-2.5 text-right">新規</th>
                            <th scope="col" class="px-3 py-2.5 text-right">上書き</th>
                            <th scope="col" class="px-3 py-2.5 text-right">エラー</th>
                            <th scope="col" class="px-3 py-2.5 text-right">警告</th>
                            <th scope="col" class="px-4 py-2.5"><span class="sr-only">詳細</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in history.data" :key="item.id" class="h-11 [&>*]:border-t [&>*]:border-stone-100" :class="current?.id === item.id ? 'bg-stone-50' : 'hover:bg-stone-50'">
                            <td class="px-4 whitespace-nowrap text-stone-600 tabular-nums">{{ formatDateTime(item.finished_at) }}</td>
                            <td class="max-w-72 truncate px-3 text-stone-900" :title="item.file_name">{{ item.file_name }}</td>
                            <td class="px-3 whitespace-nowrap text-stone-700">{{ item.source_label }}</td>
                            <td class="px-3">
                                <span class="rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap" :class="pillClass(importTone(item))">{{ TONE_LABELS[importTone(item)] }}</span>
                            </td>
                            <td class="px-3 text-right text-stone-700 tabular-nums">{{ item.created_line_count ?? "—" }}</td>
                            <td class="px-3 text-right text-stone-700 tabular-nums">{{ item.updated_line_count ?? "—" }}</td>
                            <td class="px-3 text-right tabular-nums" :class="(item.error_row_count ?? 0) > 0 ? 'font-semibold text-red-700' : 'text-stone-700'">
                                {{ item.error_row_count ?? "—" }}
                            </td>
                            <td class="px-3 text-right text-stone-700 tabular-nums">{{ item.warning_count ?? "—" }}</td>
                            <td class="px-4 text-right">
                                <button type="button" class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900" @click="showImport(item.id)">
                                    詳細
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer v-if="history && history.meta.last_page > 1" class="flex items-center justify-end gap-2 border-t border-stone-200 px-4 py-3 text-[13px]">
                <button
                    type="button"
                    class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:opacity-40"
                    :disabled="history.meta.current_page <= 1"
                    @click="goToHistoryPage(history.meta.current_page - 1)"
                >
                    新しい履歴へ
                </button>
                <button
                    type="button"
                    class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:opacity-40"
                    :disabled="history.meta.current_page >= history.meta.last_page"
                    @click="goToHistoryPage(history.meta.current_page + 1)"
                >
                    古い履歴へ
                </button>
            </footer>
        </section>
    </div>
</template>
