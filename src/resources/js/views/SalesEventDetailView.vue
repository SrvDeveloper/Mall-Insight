<script setup lang="ts">
import { computed, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { deleteSalesEvent, fetchSalesEvent } from "@/api/salesEvents";
import SalesEventFormDialog from "@/components/salesEvent/SalesEventFormDialog.vue";
import SalesEventPeriods from "@/components/salesEvent/SalesEventPeriods.vue";
import { isMall, setEventFlash, takeEventFlash } from "@/components/salesEvent/eventFormat";
import type { SalesEventPeriod, SalesEventResponse, SalesEventWithPeriods } from "@/types/api";

/**
 * イベントの画面（バックログ B-129、決定記録 K-114）。イベントの一覧でイベントを押すと移る。
 * イベントの名前・モール・メモと、期間の一覧を出し、期間を追加・編集・削除できる。イベント自体の変更・削除もここでする。
 * 期間を押すと、期間の画面（品番ごとの倍率）に移る。一覧で選んだモール（mall）は URL に持たせ、一覧へ戻るときに引き継ぐ。
 */

const route = useRoute();
const router = useRouter();

const eventId = computed(() => Number(route.params.eventId));
const result = shallowRef<SalesEventResponse | null>(null);
const isLoading = ref(false);
const loadError = ref<"not_found" | "failed" | null>(null);
const message = ref<string | null>(takeEventFlash());

let latest = 0;

async function load(): Promise<void> {
    const requestId = ++latest;
    isLoading.value = true;
    loadError.value = null;
    try {
        const response = await fetchSalesEvent(eventId.value);
        if (requestId === latest) {
            result.value = response;
        }
    } catch (error) {
        if (requestId === latest) {
            loadError.value = error instanceof ApiError && error.status === 404 ? "not_found" : "failed";
        }
    } finally {
        if (requestId === latest) {
            isLoading.value = false;
        }
    }
}

watch(eventId, () => void load(), { immediate: true });

const event = computed(() => (result.value?.data.id === eventId.value ? result.value.data : null));
const maxDays = computed(() => result.value?.meta.max_days ?? 31);

/** 一覧へ戻る（URL のモール、無ければイベントの最初のモールのタブを開く）。 */
const listLink = computed(() => {
    const mall = isMall(route.query.mall) ? route.query.mall : event.value?.malls[0]?.value;
    return { name: "sales-events", query: mall ? { mall } : {} };
});

async function onPeriodsChanged(text: string): Promise<void> {
    message.value = text;
    await load();
}

async function refreshAfterPeriodAdd(period: SalesEventPeriod, text: string): Promise<void> {
    const refreshed = await fetchSalesEvent(eventId.value);
    if (!refreshed.data.periods.some((item) => item.id === period.id)) {
        throw new Error("追加した期間が一覧に表示されていません。");
    }
    result.value = refreshed;
    message.value = text;
}

// イベントの変更のモーダル
const isFormOpen = ref(false);

function openForm(): void {
    message.value = null;
    isFormOpen.value = true;
}

async function refreshAfterEventSave(saved: SalesEventWithPeriods): Promise<void> {
    const refreshed = await fetchSalesEvent(saved.id);
    const reflected = refreshed.data;
    if (
        reflected.name !== saved.name ||
        reflected.note !== saved.note ||
        reflected.malls.length !== saved.malls.length ||
        !saved.malls.every((mall) => reflected.malls.some((item) => item.value === mall.value))
    ) {
        throw new Error("保存したイベントが画面に反映されていません。");
    }
    result.value = refreshed;
    message.value = `${saved.name}を保存しました。`;
}

async function remove(target: SalesEventWithPeriods): Promise<void> {
    if (!window.confirm(`${target.name}を削除しますか？期間（${target.periods.length}件）と担当者が入れた倍率も消えます。`)) {
        return;
    }
    try {
        await deleteSalesEvent(target.id);
        setEventFlash(`${target.name}を削除しました。`);
        await router.push(listLink.value);
    } catch {
        // 通信エラーは全体通知に表示される
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1.5">
                <p class="text-xs text-stone-500">
                    在庫試算 /
                    <RouterLink :to="listLink" class="underline-offset-4 hover:text-stone-900 hover:underline" data-testid="back-to-events">イベント</RouterLink>
                    <template v-if="event"> / {{ event.name }}</template>
                </p>
                <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[26px] leading-tight font-bold tracking-tight text-stone-900">
                    {{ event?.name ?? "イベント" }}
                    <span v-if="event" class="flex flex-wrap gap-1" data-testid="event-malls">
                        <span v-for="mall in event.malls" :key="mall.value" class="rounded-full border border-stone-200 bg-white px-2 py-0.5 text-xs font-normal tracking-normal text-stone-600">{{
                            mall.label
                        }}</span>
                    </span>
                </h1>
                <p v-if="event?.note" class="text-[13px] text-stone-600" data-testid="event-note">{{ event.note }}</p>
            </div>
            <div v-if="event" class="flex items-center gap-2">
                <button type="button" class="h-9 rounded-lg border border-stone-300 bg-white px-3 text-[13px] font-medium text-stone-700 hover:bg-stone-100" data-testid="edit-event" @click="openForm">
                    イベントを編集
                </button>
                <button type="button" class="h-9 rounded-lg px-3 text-[13px] font-medium text-red-700 hover:bg-red-50" data-testid="delete-event" @click="remove(event)">削除</button>
            </div>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status" data-testid="message">
            <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m3.5 8.5 3 3 6-7" />
            </svg>
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <div v-if="loadError === 'not_found'" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center" data-testid="not-found">
            <p class="text-sm text-stone-600">このイベントは見つかりませんでした。削除された可能性があります。</p>
            <RouterLink :to="listLink" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100">イベントの一覧へ</RouterLink>
        </div>
        <div v-else-if="loadError === 'failed'" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">イベントを表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>
        <div v-else-if="!event" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else :class="isLoading ? 'opacity-60 transition-opacity' : ''" :aria-busy="isLoading">
            <SalesEventPeriods :event="event" :max-days="maxDays" :refresh-after-add="refreshAfterPeriodAdd" @changed="onPeriodsChanged" />
        </div>

        <SalesEventFormDialog v-if="isFormOpen && event" :event="event" :max-days="maxDays" :refresh-after-save="refreshAfterEventSave" @close="isFormOpen = false" />
    </div>
</template>
