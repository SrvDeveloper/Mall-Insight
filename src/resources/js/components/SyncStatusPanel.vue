<script setup lang="ts" generic="T extends SyncRecordBase">
import { computed, onMounted, ref, shallowRef } from "vue";
import type { SyncRecordBase, SyncState } from "@/types/api";

/**
 * 外部システムからの取得状況と、手動での取得（決定記録 K-017・K-018・K-021）。
 * 最後の取得が失敗していても、表示中のデータがいつ時点のものかを必ず示す。
 */

const props = defineProps<{
    /** 取得元のシステム名（例：CrossWalker） */
    source: string;
    /** 自動取得の説明（例：毎朝6時） */
    schedule: string;
    /** 取得したものの名前（例：品番とSKU） */
    subject: string;
    loadState: () => Promise<SyncState<T>>;
    runSync: () => Promise<T>;
    /** 成功した取得の内容を1行で表す */
    summarize: (sync: T) => string;
}>();
const emit = defineEmits<{ synced: [sync: T] }>();

const state = shallowRef<SyncState<T> | null>(null);
const isRunning = ref(false);
const hasLoadError = ref(false);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));

async function load(): Promise<void> {
    try {
        state.value = await props.loadState();
        hasLoadError.value = false;
    } catch {
        hasLoadError.value = true;
    }
}

async function run(): Promise<void> {
    if (isRunning.value) {
        return;
    }
    isRunning.value = true;
    try {
        const sync = await props.runSync();
        await load();
        emit("synced", sync);
    } catch {
        // 通信エラー・実行中（409）は全体通知に表示される
    } finally {
        isRunning.value = false;
    }
}

onMounted(load);

const latest = computed(() => state.value?.latest ?? null);
const lastSucceeded = computed(() => state.value?.last_succeeded ?? null);
const latestFailed = computed(() => latest.value?.status === "failed");
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-lg border px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        :class="latestFailed ? 'border-amber-300 bg-amber-50' : 'border-stone-200 bg-white'"
        :aria-label="`${source}からの取得状況`"
    >
        <div class="flex min-w-0 flex-col gap-0.5 text-sm">
            <template v-if="hasLoadError">
                <p class="text-stone-600">取得状況を表示できませんでした。</p>
            </template>
            <template v-else-if="!state">
                <p class="text-stone-500">取得状況を読み込み中…</p>
            </template>
            <template v-else-if="!latest">
                <p class="font-semibold text-stone-900">{{ source }}からまだ取得していません。</p>
                <p class="text-stone-500">「取得実行」を押すと、{{ source }}から{{ subject }}を取り込みます。以後は{{ schedule }}に自動で取得します。</p>
            </template>
            <template v-else-if="latestFailed">
                <p class="font-semibold text-amber-900">{{ formatDateTime(latest.finished_at) }} の取得（{{ latest.triggered_by_label }}）に失敗しました。</p>
                <p class="text-amber-900">{{ latest.error_message }}</p>
                <p class="text-amber-800">
                    {{
                        lastSucceeded
                            ? `表示中のデータは ${formatDateTime(lastSucceeded.finished_at)} に取得したものです。`
                            : `まだ一度も取得に成功していないため、表示中のデータは${source}のものではありません。`
                    }}
                </p>
            </template>
            <template v-else>
                <p class="text-stone-900">
                    <span class="font-semibold">最終取得 {{ formatDateTime(latest.finished_at) }}</span>
                    <span class="text-stone-500">（{{ latest.triggered_by_label }}）</span>
                </p>
                <p class="text-stone-500 tabular-nums">{{ summarize(latest) }}。{{ schedule }}に自動で取得します。</p>
            </template>
        </div>

        <button
            type="button"
            class="shrink-0 self-start rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-800 hover:bg-stone-100 disabled:cursor-wait disabled:opacity-60 sm:self-auto"
            :disabled="isRunning"
            @click="run"
        >
            {{ isRunning ? "取得中…" : "取得実行" }}
        </button>
    </section>
</template>
