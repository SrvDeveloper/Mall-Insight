<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { deleteSalesEvent, fetchSalesEvent, fetchSalesEvents } from "@/api/salesEvents";
import SalesEventDetail from "@/components/salesEvent/SalesEventDetail.vue";
import SalesEventForm from "@/components/salesEvent/SalesEventForm.vue";
import { eventNames, formatMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";
import type { SalesEvent, SalesEventDetailResponse, SalesEventListResponse } from "@/types/api";

/**
 * イベント（バックログ B-129、決定記録 K-112）。左メニュー「在庫試算」の需要予測の下に置く。
 * 担当者がイベント（キャンペーン）の名前・期間・モールを登録する。イベントの日は平均日販から除き、これからのイベントの日は、
 * 品番ごとの倍率で需要予測と在庫予測を増やす。倍率は、同じ名前の過去のイベントで普段の何倍売れたかからシステムが候補を出し、担当者が直せる。
 *
 * - 一覧は初日の新しい順。「これから」（始まる前・開催中）と「終わった」で絞り込む。チャネルごとに、これからのイベントは全体の候補、
 *   終わったイベントは普段の何倍売れたかを出す。
 * - 行を押すと、一覧の下に品番ごとの倍率を出す（選んだイベントは URL に持たせる）。
 * - 「コピー」で名前・モール・メモを写して、期間だけ入れ直して登録できる。
 */

type Filter = "active" | "ended" | "all";

const route = useRoute();
const router = useRouter();

const result = shallowRef<SalesEventListResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const message = ref<string | null>(null);
const filter = ref<Filter>("active");

const selectedId = computed<number | null>(() => (typeof route.query.event === "string" && /^\d+$/.test(route.query.event) ? Number(route.query.event) : null));
const detail = shallowRef<SalesEventDetailResponse | null>(null);
const isDetailLoading = ref(false);
const detailError = ref(false);

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchSalesEvents();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

let latestDetail = 0;

async function loadDetail(): Promise<void> {
    const id = selectedId.value;
    const requestId = ++latestDetail;
    if (id === null) {
        detail.value = null;
        return;
    }
    isDetailLoading.value = true;
    detailError.value = false;
    try {
        const response = await fetchSalesEvent(id);
        if (requestId === latestDetail) {
            detail.value = response;
        }
    } catch {
        if (requestId === latestDetail) {
            detailError.value = true;
        }
    } finally {
        if (requestId === latestDetail) {
            isDetailLoading.value = false;
        }
    }
}

onMounted(() => {
    void load();
    void loadDetail();
});
watch(selectedId, () => void loadDetail());

const events = computed(() => result.value?.data ?? []);
const counts = computed<Record<Filter, number>>(() => ({
    active: events.value.filter((event) => event.status !== "ended").length,
    ended: events.value.filter((event) => event.status === "ended").length,
    all: events.value.length,
}));
const visibleEvents = computed(() => events.value.filter((event) => filter.value === "all" || (filter.value === "ended" ? event.status === "ended" : event.status !== "ended")));
const names = computed(() => eventNames(events.value));

const detailSection = useTemplateRef<HTMLElement>("detailSection");

async function select(event: SalesEvent): Promise<void> {
    await router.replace({ query: { ...route.query, event: selectedId.value === event.id ? undefined : String(event.id) } });
    if (selectedId.value !== null) {
        await nextTick();
        detailSection.value?.scrollIntoView?.({ behavior: "smooth", block: "nearest" });
    }
}

function closeDetail(): void {
    void router.replace({ query: { ...route.query, event: undefined } });
}

function onDetailSaved(saved: SalesEventDetailResponse): void {
    detail.value = saved;
    void load();
}

// 登録・変更・コピーのモーダル
const editing = ref<{ event: SalesEvent | null; mode: "create" | "edit" | "copy" } | null>(null);
const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        closeForm();
    }
}

async function openForm(event: SalesEvent | null, mode: "create" | "edit" | "copy"): Promise<void> {
    editing.value = { event, mode };
    message.value = null;
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    closeButton.value?.focus();
}

function closeForm(): void {
    editing.value = null;
    document.removeEventListener("keydown", onKeydown);
}

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

async function onSaved(saved: SalesEvent): Promise<void> {
    const mode = editing.value?.mode;
    message.value = `${saved.name}（${periodLabel(saved.starts_on, saved.ends_on)}）を${mode === "edit" ? "保存" : "登録"}しました。需要予測と在庫予測に反映されます。`;
    closeForm();
    if (saved.status === "ended" && filter.value === "active") {
        filter.value = "all";
    }
    await load();
    if (selectedId.value === saved.id) {
        void loadDetail();
    }
}

async function remove(event: SalesEvent): Promise<void> {
    if (!window.confirm(`${event.name}（${periodLabel(event.starts_on, event.ends_on)}）を削除しますか？担当者が入れた倍率も消えます。`)) {
        return;
    }
    try {
        await deleteSalesEvent(event.id);
        message.value = `${event.name}（${periodLabel(event.starts_on, event.ends_on)}）を削除しました。`;
        if (selectedId.value === event.id) {
            closeDetail();
        }
        void load();
    } catch {
        // 通信エラーは全体通知に表示される
    }
}

const formTitle = computed(() => (editing.value?.mode === "edit" ? "イベントを編集" : editing.value?.mode === "copy" ? "イベントをコピーして登録" : "イベントを登録"));

const statusClass = (event: SalesEvent): string =>
    event.status === "ongoing" ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : event.status === "upcoming" ? "bg-sky-50 text-sky-800 ring-sky-200" : "bg-stone-100 text-stone-600 ring-stone-200";
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / イベント</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">イベント</h1>
                <p class="max-w-3xl text-[13px] text-stone-600">
                    楽天スーパーセールやプライムデーなどのイベントを登録します。イベントの日は平均日販から除き、これからのイベントの日は品番ごとの倍率で需要予測を増やします。
                    倍率は、同じ名前の過去のイベントで普段の何倍売れたかから候補を出します。過去のイベントも同じ名前で登録すると、候補を出せるようになります。
                </p>
            </div>
            <button
                type="button"
                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-700"
                data-testid="add-event"
                @click="openForm(null, 'create')"
            >
                <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M8 3v10M3 8h10" />
                </svg>
                イベントを登録
            </button>
        </header>

        <p v-if="message" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status" data-testid="message">
            <svg class="size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m3.5 8.5 3 3 6-7" />
            </svg>
            <span class="flex-1">{{ message }}</span>
            <button type="button" class="rounded px-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" @click="message = null">閉じる</button>
        </p>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                    <button
                        v-for="option in [
                            { value: 'active' as Filter, label: 'これから' },
                            { value: 'ended' as Filter, label: '終わった' },
                            { value: 'all' as Filter, label: 'すべて' },
                        ]"
                        :key="option.value"
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                        :aria-pressed="filter === option.value"
                        :data-testid="`filter-${option.value}`"
                        @click="filter = option.value"
                    >
                        {{ option.label }} <span class="text-stone-500 tabular-nums">{{ result ? counts[option.value] : "" }}</span>
                    </button>
                </div>
                <p class="text-[11px] text-stone-400">行を押すと、品番ごとの倍率を下に出します</p>
            </div>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">イベントを表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>

            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

            <div v-else-if="visibleEvents.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                <template v-if="events.length > 0">{{ filter === "ended" ? "終わったイベントはありません。" : "これからのイベントはありません。" }}</template>
                <template v-else>
                    イベントはまだありません。過去のイベントも登録すると、平均日販から除き、倍率の候補を出せるようになります。
                    <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-[13px] font-semibold text-stone-700 hover:bg-stone-100" @click="openForm(null, 'create')">
                        イベントを登録
                    </button>
                </template>
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-[60rem] border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">名前</th>
                            <th scope="col" class="px-3 py-2.5">期間</th>
                            <th scope="col" class="px-3 py-2.5">モール</th>
                            <th scope="col" class="px-3 py-2.5">倍率<span class="ml-1 font-normal text-stone-400">（これからは候補、終わったものは実績）</span></th>
                            <th scope="col" class="px-3 py-2.5">状態</th>
                            <th scope="col" class="px-3 py-2.5"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in visibleEvents"
                            :key="event.id"
                            class="cursor-pointer [&>*]:border-t [&>*]:border-stone-100"
                            :class="selectedId === event.id ? 'bg-stone-100' : 'hover:bg-stone-50'"
                            :aria-selected="selectedId === event.id"
                            data-testid="event-row"
                            @click="select(event)"
                        >
                            <td class="px-4 py-3 align-top">
                                <button
                                    type="button"
                                    class="text-left font-semibold text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    :aria-expanded="selectedId === event.id"
                                    @click.stop="select(event)"
                                >
                                    {{ event.name }}
                                </button>
                                <p v-if="event.note" class="mt-0.5 max-w-56 truncate text-[11px] text-stone-500" :title="event.note">{{ event.note }}</p>
                            </td>
                            <td class="px-3 py-3 align-top whitespace-nowrap tabular-nums">
                                {{ periodLabel(event.starts_on, event.ends_on) }}
                                <span class="ml-1 text-[11px] text-stone-400">{{ event.days }}日間</span>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="flex flex-wrap gap-1">
                                    <span v-for="mall in event.malls" :key="mall.value" class="rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] whitespace-nowrap text-stone-600">{{
                                        mall.label
                                    }}</span>
                                </span>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <span class="flex flex-col gap-0.5">
                                    <span v-for="multiplier in event.multipliers" :key="multiplier.channel" class="text-xs whitespace-nowrap text-stone-600 tabular-nums" data-testid="row-multiplier">
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
                            <td class="px-3 py-3 align-top whitespace-nowrap">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1" :class="statusClass(event)">{{ event.status_label }}</span>
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap" @click.stop>
                                <div class="inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="h-8 rounded-lg px-2.5 text-xs font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900"
                                        data-testid="edit-event"
                                        @click="openForm(event, 'edit')"
                                    >
                                        編集
                                    </button>
                                    <button
                                        type="button"
                                        class="h-8 rounded-lg border border-stone-300 bg-white px-2.5 text-xs font-medium text-stone-900 hover:bg-stone-100"
                                        data-testid="copy-event"
                                        @click="openForm(event, 'copy')"
                                    >
                                        コピー
                                    </button>
                                    <button type="button" class="h-8 rounded-lg px-2.5 text-xs font-medium text-red-700 hover:bg-red-50" data-testid="delete-event" @click="remove(event)">削除</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div ref="detailSection">
            <div v-if="selectedId !== null && detailError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-10 text-center">
                <p class="text-sm text-stone-600">倍率を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="loadDetail">再読み込み</button>
            </div>
            <div v-else-if="selectedId !== null && !detail" class="rounded-xl border border-stone-200 bg-white px-6 py-10 text-center text-sm text-stone-500">読み込み中…</div>
            <SalesEventDetail v-else-if="selectedId !== null && detail" :key="detail.data.id" :detail="detail" :is-loading="isDetailLoading" @saved="onDetailSaved" @close="closeDetail" />
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="closeForm">
            <div role="dialog" aria-modal="true" aria-labelledby="event-form-title" class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5">
                <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div class="flex flex-col gap-0.5">
                        <h2 id="event-form-title" class="text-base font-bold text-stone-900">{{ formTitle }}</h2>
                        <p class="text-xs text-stone-500">
                            {{ editing.mode === "copy" ? "名前・モール・メモを写しました。期間を入れてください。" : `期間は${result?.meta.max_days ?? 31}日までです。` }}
                        </p>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                        aria-label="閉じる"
                        @click="closeForm"
                    >
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" />
                        </svg>
                    </button>
                </header>
                <div class="overflow-y-auto px-5 py-5">
                    <SalesEventForm :event="editing.event" :mode="editing.mode" :names="names" :max-days="result?.meta.max_days ?? 31" @saved="onSaved" @cancel="closeForm" />
                </div>
            </div>
        </div>
    </div>
</template>
