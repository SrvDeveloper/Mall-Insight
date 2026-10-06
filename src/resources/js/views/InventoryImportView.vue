<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { fetchInventoryDates, fetchLatestZeroStockViewSync, runZeroStockViewSync } from "@/api/inventory";
import SyncStatusPanel from "@/components/SyncStatusPanel.vue";
import type { InventoryDate, Paginated, ZeroStockViewSync } from "@/types/api";

/**
 * 在庫の取得（バックログ B-003）。ZeroStockView から日次在庫を取得し、取得済みの在庫基準日を確認する。
 * 在庫の鮮度（最新の在庫基準日から何日たっているか）を必ず示す。
 */

/** 最新の在庫基準日からこの日数以上たっていたら、在庫が古いと警告する（土日をはさむ月曜の朝は3日になるため4日とする。K-021） */
const STALE_AFTER_DAYS = 4;

const dates = ref<Paginated<InventoryDate> | null>(null);
const isLoading = ref(false);
const hasLoadError = ref(false);
const page = ref(1);

async function loadDates(): Promise<void> {
    isLoading.value = true;
    try {
        dates.value = await fetchInventoryDates(page.value);
        hasLoadError.value = false;
    } catch {
        hasLoadError.value = true;
    } finally {
        isLoading.value = false;
    }
}

function goToPage(next: number): void {
    page.value = next;
    void loadDates();
}

function onSynced(): void {
    page.value = 1;
    void loadDates();
}

onMounted(loadDates);

const dateFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", weekday: "short" });
const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });

/** "YYYY-MM-DD"（日本時間の日付）を日本時間の正午として扱い、表示時の日付ずれを防ぐ */
const parseStockDate = (value: string): Date => new Date(`${value}T12:00:00+09:00`);
const formatStockDate = (value: string): string => dateFormat.format(parseStockDate(value));
const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));

function todayInTokyo(): string {
    return new Intl.DateTimeFormat("sv-SE", { timeZone: "Asia/Tokyo" }).format(new Date());
}

/** 最新の在庫基準日（1ページ目の先頭）と、今日からの経過日数 */
const freshness = computed(() => {
    const latest = page.value === 1 ? dates.value?.data[0]?.stock_date : undefined;
    if (!latest) {
        return null;
    }
    const days = Math.round((parseStockDate(todayInTokyo()).getTime() - parseStockDate(latest).getTime()) / 86_400_000);
    return { latest, days, isStale: days >= STALE_AFTER_DAYS };
});

function summarizeSync(sync: ZeroStockViewSync): string {
    const range = sync.from_date && sync.to_date ? `${sync.from_date}〜${sync.to_date} を確認し、` : "";
    const created = sync.created_sku_count ? `、CrossWalkerに無いSKU ${sync.created_sku_count}件` : "";
    return `${range}在庫基準日 ${sync.stock_date_count}日分・${sync.sku_row_count}行を取得${created}`;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-xl font-bold tracking-tight text-stone-900">在庫の取得</h1>
            <p class="text-sm text-stone-500">ZeroStockView から、SKUごと・在庫の区分（Amazon自社出荷、Amazon FBA、BOSS自社倉庫、BOSS RFC、フリー在庫、ECストック）ごとの日次在庫を取得します。</p>
        </header>

        <SyncStatusPanel
            source="ZeroStockView"
            schedule="毎朝10時"
            subject="日次在庫"
            :load-state="fetchLatestZeroStockViewSync"
            :run-sync="runZeroStockViewSync"
            :summarize="summarizeSync"
            @synced="onSynced"
        />

        <p
            v-if="freshness"
            class="rounded-md px-3 py-2 text-sm"
            :class="freshness.isStale ? 'bg-amber-50 font-semibold text-amber-900' : 'bg-stone-100 text-stone-700'"
            :role="freshness.isStale ? 'alert' : undefined"
        >
            最新の在庫基準日は {{ formatStockDate(freshness.latest) }}（{{ freshness.days === 0 ? "今日" : `${freshness.days}日前` }}）です。
            <template v-if="freshness.isStale">在庫が古くなっています。ZeroStockView で在庫の取込が済んでいるか確認してください。</template>
        </p>

        <section class="overflow-hidden rounded-lg border border-stone-200 bg-white" :aria-busy="isLoading">
            <h2 class="border-b border-stone-200 px-4 py-2.5 text-sm font-semibold text-stone-700">取得済みの在庫基準日</h2>

            <div v-if="hasLoadError" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <p class="text-sm text-stone-600">一覧を表示できませんでした。</p>
                <button type="button" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="loadDates">再読み込み</button>
            </div>
            <div v-else-if="!dates" class="px-6 py-12 text-center text-sm text-stone-500">読み込み中…</div>
            <div v-else-if="dates.data.length === 0" class="px-6 py-12 text-center text-sm text-stone-500">在庫がまだありません。「ZeroStockViewから取得」を押すと、ここに表示されます。</div>
            <table v-else class="w-full text-left text-sm" :class="isLoading ? 'opacity-60' : ''">
                <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold text-stone-500">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">在庫基準日</th>
                        <th scope="col" class="px-4 py-2.5 text-right">SKU数</th>
                        <th scope="col" class="px-4 py-2.5">最後に取得した日時</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <tr v-for="date in dates.data" :key="date.stock_date">
                        <td class="px-4 py-2.5 font-semibold whitespace-nowrap text-stone-900 tabular-nums">{{ formatStockDate(date.stock_date) }}</td>
                        <td class="px-4 py-2.5 text-right text-stone-700 tabular-nums">{{ date.sku_count }}</td>
                        <td class="px-4 py-2.5 whitespace-nowrap text-stone-500 tabular-nums">{{ formatDateTime(date.fetched_at) }}</td>
                    </tr>
                </tbody>
            </table>

            <footer v-if="dates && dates.meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-sm text-stone-600">
                <p class="tabular-nums">{{ dates.meta.total }}日分中 {{ dates.meta.from }}–{{ dates.meta.to }}日目</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="dates.meta.current_page <= 1 || isLoading"
                        @click="goToPage(dates.meta.current_page - 1)"
                    >
                        新しい日へ
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-stone-300 px-3 py-1.5 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="dates.meta.current_page >= dates.meta.last_page || isLoading"
                        @click="goToPage(dates.meta.current_page + 1)"
                    >
                        古い日へ
                    </button>
                </div>
            </footer>
        </section>
    </div>
</template>
