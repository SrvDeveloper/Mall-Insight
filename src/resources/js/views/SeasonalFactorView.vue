<script setup lang="ts">
import { computed, nextTick, onMounted, ref, shallowRef, useTemplateRef, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { fetchSeasonalFactor, fetchSeasonalFactors } from "@/api/seasonalFactors";
import SeasonalFactorEditor from "@/components/seasonalFactor/SeasonalFactorEditor.vue";
import { MONTH_LABELS, SOURCE_LABELS, formatFactor } from "@/components/seasonalFactor/seasonalFormat";
import { formatDateTime } from "@/components/salesTarget/format";
import type { SeasonalFactorDetailResponse, SeasonalFactorListResponse } from "@/types/api";

/**
 * 季節係数（バックログ B-106、決定記録 K-113）。左メニュー「在庫試算」のイベントの下に置く。
 * 季節によって売れ方が変わる品番に、1月～12月の季節係数を担当者が登録する。登録した品番だけ、需要予測と在庫予測で月ごとに増減させる。
 * 販売実績から求めた係数は、在庫の記録が無い期間の欠品を除けず予測が外れたため、そのまま使わずに初期値として出す。
 *
 * - 一覧は対象品番（選定の順）。登録した係数と、実績から求めた係数を12か月の小さな棒で並べる。
 * - 行を押すと、一覧の下に登録の画面を出す（選んだ品番は URL に持たせる）。
 */

const route = useRoute();
const router = useRouter();

const result = shallowRef<SeasonalFactorListResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const filter = ref<"all" | "registered">("all");

const selectedItemNo = computed<string | null>(() => (typeof route.query.item === "string" && route.query.item !== "" ? route.query.item : null));
const detail = shallowRef<SeasonalFactorDetailResponse | null>(null);
const detailError = ref(false);

async function load(): Promise<void> {
    isLoading.value = true;
    hasError.value = false;
    try {
        result.value = await fetchSeasonalFactors();
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

let latestDetail = 0;

async function loadDetail(): Promise<void> {
    const itemNo = selectedItemNo.value;
    const requestId = ++latestDetail;
    detailError.value = false;
    if (itemNo === null) {
        detail.value = null;
        return;
    }
    try {
        const response = await fetchSeasonalFactor(itemNo);
        if (requestId === latestDetail) {
            detail.value = response;
        }
    } catch {
        if (requestId === latestDetail) {
            detailError.value = true;
        }
    }
}

onMounted(() => {
    void load();
    void loadDetail();
});
watch(selectedItemNo, () => void loadDetail());

const rows = computed(() => result.value?.data ?? []);
const registeredCount = computed(() => rows.value.filter((row) => row.current?.factors).length);
const visibleRows = computed(() => (filter.value === "registered" ? rows.value.filter((row) => row.current?.factors) : rows.value));

const editorSection = useTemplateRef<HTMLElement>("editorSection");

async function select(itemNo: string): Promise<void> {
    await router.replace({ query: { ...route.query, item: selectedItemNo.value === itemNo ? undefined : itemNo } });
    if (selectedItemNo.value !== null) {
        await nextTick();
        editorSection.value?.scrollIntoView?.({ behavior: "smooth", block: "nearest" });
    }
}

function close(): void {
    void router.replace({ query: { ...route.query, item: undefined } });
}

function onSaved(saved: SeasonalFactorDetailResponse): void {
    detail.value = saved;
    void load();
}

/** 12か月の小さな棒（係数2を高さいっぱいにする）。 */
const barHeight = (factor: number): string => `${Math.max(2, Math.min(100, (factor / 2) * 100))}%`;
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">在庫試算 / 季節係数</p>
            <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">季節係数</h1>
            <p class="max-w-3xl text-[13px] text-stone-600">
                季節によって売れ方が変わる品番に、1月〜12月の季節係数（普段の月を1として何倍売れるか）を登録します。登録した品番だけ、需要予測と在庫予測で月ごとに増減させます。
                初期値には販売実績から求めた係数を出しますが、在庫の記録が2026年9月16日からのため、それより前に欠品していた月は小さく出ます。月ごとの販売数で確かめて直してください。
            </p>
        </header>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                    <button
                        v-for="option in [
                            { value: 'all' as const, label: 'すべて', count: rows.length },
                            { value: 'registered' as const, label: '登録済み', count: registeredCount },
                        ]"
                        :key="option.value"
                        type="button"
                        class="h-8 rounded-full border bg-white px-3 text-[13px]"
                        :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                        :aria-pressed="filter === option.value"
                        :data-testid="`filter-${option.value}`"
                        @click="filter = option.value"
                    >
                        {{ option.label }} <span class="text-stone-500 tabular-nums">{{ result ? option.count : "" }}</span>
                    </button>
                </div>
                <p class="text-[11px] text-stone-400">行を押すと、係数の登録を下に出します</p>
            </div>

            <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <p class="text-sm text-stone-600">季節係数を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
            </div>
            <div v-else-if="!result" class="px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>
            <div v-else-if="visibleRows.length === 0" class="px-6 py-16 text-center text-sm text-stone-500" data-testid="empty">
                {{ rows.length === 0 ? "対象品番がまだ確定されていません。" : "季節係数を登録した品番はありません。" }}
            </div>

            <div v-else class="overflow-x-auto" :class="isLoading ? 'opacity-60' : ''">
                <table class="w-full min-w-[56rem] border-separate border-spacing-0 text-left text-[13px]">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">品番</th>
                            <th scope="col" class="px-3 py-2.5">登録した係数<span class="ml-1 font-normal text-stone-400">（1〜12月）</span></th>
                            <th scope="col" class="px-3 py-2.5">実績から求めた係数<span class="ml-1 font-normal text-stone-400">（初期値）</span></th>
                            <th scope="col" class="px-3 py-2.5">更新</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in visibleRows"
                            :key="row.item_no"
                            class="cursor-pointer [&>*]:border-t [&>*]:border-stone-100"
                            :class="selectedItemNo === row.item_no ? 'bg-stone-100' : 'hover:bg-stone-50'"
                            :aria-selected="selectedItemNo === row.item_no"
                            data-testid="factor-row"
                            @click="select(row.item_no)"
                        >
                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <button
                                    type="button"
                                    class="font-mono text-sm font-medium text-stone-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-stone-900"
                                    :aria-expanded="selectedItemNo === row.item_no"
                                    @click.stop="select(row.item_no)"
                                >
                                    {{ row.item_no }}
                                </button>
                                <span class="ml-2 text-[11px] text-stone-500">{{ row.brand }}・{{ row.category }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span
                                    v-if="row.current?.factors"
                                    class="flex h-7 items-end gap-0.5"
                                    :title="row.current.factors.map((factor, index) => `${MONTH_LABELS[index]} ${formatFactor(factor)}`).join('\n')"
                                    data-testid="current-bars"
                                >
                                    <span v-for="(factor, index) in row.current.factors" :key="index" class="w-2 rounded-t-sm bg-stone-700" :style="{ height: barHeight(factor) }" />
                                </span>
                                <span v-else class="text-xs text-stone-400">未登録</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span v-if="row.initial?.factors" class="flex items-center gap-2">
                                    <span class="flex h-7 items-end gap-0.5" :title="row.initial.factors.map((factor, index) => `${MONTH_LABELS[index]} ${formatFactor(factor)}`).join('\n')">
                                        <span v-for="(factor, index) in row.initial.factors" :key="index" class="w-2 rounded-t-sm bg-sky-600/70" :style="{ height: barHeight(factor) }" />
                                    </span>
                                    <span class="text-[11px] text-stone-500">{{ row.initial.source ? SOURCE_LABELS[row.initial.source] : "" }}</span>
                                </span>
                                <span v-else class="text-xs text-stone-400">求められません</span>
                            </td>
                            <td class="px-3 py-2.5 text-xs whitespace-nowrap text-stone-500 tabular-nums">
                                <template v-if="row.current">{{ row.current.changed_at ? formatDateTime(row.current.changed_at) : "" }} {{ row.current.changed_by ?? "" }}</template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div ref="editorSection">
            <div v-if="selectedItemNo !== null && detailError" class="rounded-xl border border-stone-200 bg-white px-6 py-10 text-center text-sm text-stone-600">季節係数を表示できませんでした。</div>
            <div
                v-else-if="selectedItemNo !== null && (!detail || detail.data.item_no !== selectedItemNo)"
                class="rounded-xl border border-stone-200 bg-white px-6 py-10 text-center text-sm text-stone-500"
            >
                読み込み中…
            </div>
            <SeasonalFactorEditor v-else-if="selectedItemNo !== null && detail" :key="detail.data.item_no" :detail="detail" @saved="onSaved" @close="close" />
        </div>
    </div>
</template>
