<script setup lang="ts">
import { computed, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchSalesEventPeriod } from "@/api/salesEvents";
import SalesEventPeriodDetail from "@/components/salesEvent/SalesEventPeriodDetail.vue";
import { isMall, periodLabel, statusClass } from "@/components/salesEvent/eventFormat";
import type { SalesEventPeriodDetailResponse } from "@/types/api";

/**
 * イベントの期間の画面（バックログ B-129、決定記録 K-112・K-114）。イベントの画面で期間を押すと移る。
 * これからの期間は、品番ごとの倍率の候補と根拠を出し、担当者が使う倍率を入れられる。終了した期間は、普段の何倍売れたかを出す。
 * 見出しの上の「イベント / イベント名」から、一覧とイベントの画面へ戻れる（一覧で選んだモールを引き継ぐ）。
 */

const route = useRoute();
const router = useRouter();

const periodId = computed(() => Number(route.params.periodId));
const detail = shallowRef<SalesEventPeriodDetailResponse | null>(null);
const isLoading = ref(false);
const loadError = ref<"not_found" | "failed" | null>(null);

let latest = 0;

async function load(): Promise<void> {
    const requestId = ++latest;
    isLoading.value = true;
    loadError.value = null;
    try {
        const response = await fetchSalesEventPeriod(periodId.value);
        if (requestId !== latest) {
            return;
        }
        detail.value = response;
        // 別のイベントの期間を開いたら、URL のイベントを直す
        if (String(response.data.event_id) !== route.params.eventId) {
            void router.replace({ name: "sales-event-period", params: { eventId: String(response.data.event_id), periodId: String(response.data.id) }, query: route.query });
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

watch(periodId, () => void load(), { immediate: true });

const period = computed(() => (detail.value?.data.id === periodId.value ? detail.value.data : null));
const mallQuery = computed(() => (isMall(route.query.mall) ? { mall: route.query.mall } : {}));
const listLink = computed(() => ({ name: "sales-events", query: isMall(route.query.mall) ? mallQuery.value : period.value?.event.malls[0] ? { mall: period.value.event.malls[0].value } : {} }));
const eventLink = computed(() => ({ name: "sales-event", params: { eventId: String(period.value?.event_id ?? route.params.eventId) }, query: mallQuery.value }));

function onSaved(saved: SalesEventPeriodDetailResponse): void {
    detail.value = saved;
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">
                在庫試算 /
                <RouterLink :to="listLink" class="underline-offset-4 hover:text-stone-900 hover:underline" data-testid="back-to-events">イベント</RouterLink>
                <template v-if="period">
                    /
                    <RouterLink :to="eventLink" class="underline-offset-4 hover:text-stone-900 hover:underline" data-testid="back-to-event">{{ period.event.name }}</RouterLink>
                    / {{ periodLabel(period.starts_on, period.ends_on) }}
                </template>
            </p>
            <h1 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[26px] leading-tight font-bold tracking-tight text-stone-900 tabular-nums">
                <template v-if="period">
                    {{ period.event.name }}
                    <span class="text-xl font-semibold">{{ periodLabel(period.starts_on, period.ends_on) }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-normal ring-1" :class="statusClass(period.status)" data-testid="period-status">{{
                        period.status_label
                    }}</span>
                </template>
                <template v-else>イベントの期間</template>
            </h1>
            <p v-if="period" class="text-[13px] text-stone-600 tabular-nums" data-testid="period-summary">
                {{ period.days }}日間・{{ period.event.malls.map((mall) => mall.label).join("・") }}<template v-if="period.note">・{{ period.note }}</template>
                <template v-if="period.status !== 'ended' && period.remaining_days < period.days">・今日からの残り {{ period.remaining_days }}日に倍率を掛けます</template>
            </p>
        </header>

        <div v-if="loadError === 'not_found'" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center" data-testid="not-found">
            <p class="text-sm text-stone-600">この期間は見つかりませんでした。削除された可能性があります。</p>
            <RouterLink :to="eventLink" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100">イベントの画面へ</RouterLink>
        </div>
        <div v-else-if="loadError === 'failed'" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">倍率を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>
        <div v-else-if="!period || !detail" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>
        <SalesEventPeriodDetail v-else :key="period.id" :detail="detail" :is-loading="isLoading" @saved="onSaved" />
    </div>
</template>
