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

/** 在庫の区分（K-020）。説明の札に使う。 */
const WAREHOUSES = ["Amazon自社出荷", "Amazon FBA", "BOSS自社倉庫", "BOSS RFC", "フリー在庫", "ECストック"];
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-col gap-1.5">
            <p class="text-xs text-stone-500">取込 / 在庫の取得</p>
            <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">在庫の取得</h1>
            <p class="text-[13px] text-stone-600">ZeroStockView から、SKUごと・在庫の区分ごとの日次在庫を取得します。</p>
            <ul class="flex flex-wrap gap-1.5 pt-1" aria-label="在庫の区分">
                <li v-for="warehouse in WAREHOUSES" :key="warehouse" class="rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ warehouse }}</li>
            </ul>
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
            class="flex items-start gap-3 rounded-xl border px-5 py-3 text-[13px]"
            :class="freshness.isStale ? 'border-amber-300 bg-amber-50 font-semibold text-amber-900' : 'border-stone-200 bg-white text-stone-700'"
            :role="freshness.isStale ? 'alert' : undefined"
            data-testid="freshness"
        >
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="freshness.isStale ? 'bg-amber-500' : 'bg-stone-900'" aria-hidden="true" />
            <span>
                最新の在庫基準日は <strong class="font-semibold text-stone-900 tabular-nums">{{ formatStockDate(freshness.latest) }}</strong
                >（{{ freshness.days === 0 ? "今日" : `${freshness.days}日前` }}）です。
                <template v-if="freshness.isStale">在庫が古くなっています。ZeroStockView で在庫の取込が済んでいるか確認してください。</template>
            </span>
        </p>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading">
            <h2 class="border-b border-stone-200 px-4 py-3.5 text-sm font-semibold text-stone-900">取得済みの在庫基準日</h2>

            <div v-if="hasLoadError" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <p class="text-sm text-stone-600">一覧を表示できませんでした。</p>
                <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="loadDates">再読み込み</button>
            </div>
            <div v-else-if="!dates" class="px-6 py-12 text-center text-sm text-stone-500">読み込み中…</div>
            <div v-else-if="dates.data.length === 0" class="px-6 py-12 text-center text-sm text-stone-500">在庫がまだありません。上の「取得実行」を押すと、ここに表示されます。</div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[36rem] border-separate border-spacing-0 text-left text-[13px]" :class="isLoading ? 'opacity-60' : ''">
                    <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">在庫基準日</th>
                            <th scope="col" class="px-4 py-2.5 text-right">SKU数</th>
                            <th scope="col" class="px-4 py-2.5">最後に取得した日時</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="date in dates.data" :key="date.stock_date" class="h-11 hover:bg-stone-50 [&>*]:border-t [&>*]:border-stone-100">
                            <td class="px-4 font-medium whitespace-nowrap text-stone-900 tabular-nums">{{ formatStockDate(date.stock_date) }}</td>
                            <td class="px-4 text-right text-stone-900 tabular-nums">{{ date.sku_count }}</td>
                            <td class="px-4 whitespace-nowrap text-stone-500 tabular-nums">{{ formatDateTime(date.fetched_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="dates && dates.meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-stone-200 px-4 py-3 text-[13px] text-stone-600">
                <p class="tabular-nums">{{ dates.meta.total }}日分中 {{ dates.meta.from }}–{{ dates.meta.to }}日目</p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="dates.meta.current_page <= 1 || isLoading"
                        @click="goToPage(dates.meta.current_page - 1)"
                    >
                        新しい日へ
                    </button>
                    <button
                        type="button"
                        class="h-8 rounded-lg border border-stone-300 px-3 font-medium hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
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
