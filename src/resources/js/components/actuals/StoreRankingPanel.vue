<script setup lang="ts">
import { computed, nextTick, ref, shallowRef, useTemplateRef, watch } from "vue";
import { fetchStoreRanking } from "@/api/actuals";
import RankingDonut from "@/components/actuals/RankingDonut.vue";
import StockoutIcon from "@/components/actuals/StockoutIcon.vue";
import { donutSegments, swatchStyle } from "@/components/actuals/rankingDonut";
import { STORE_COLORS, type ActualMeasure } from "@/components/actuals/actualRows";
import { formatQuantity } from "@/components/inventoryTrend/trendRows";
import { formatYen } from "@/components/salesTarget/format";
import type { RankingStockout, SalesUnit, StoreRankingResponse, StoreSales, StoreSalesProducts } from "@/types/api";

/**
 * 店舗売上の月・日の欄を押したときに、表とは別のセクション（カード）として表の下に出す売上上位の品番（20件、K-091・K-092・K-096・K-099）。店舗の行の欄ならその店舗、合計の行なら全店舗。
 * 金額と販売数のどちらの多い順かを切り替えられる（初めは店舗売上で見ている方）。各行に、その店舗・期間の全体に対する割合を棒と数字で出す。
 * CrossWalkerに未登録のSKUは、品番の順位には入れずに件数と合計を添える。別の欄を押すと読み込み直し、読み込むまでは
 * 前の一覧を薄くして残す（表の高さが変わらないように）。開いたときは一覧が見えるところまで画面を動かす。×で閉じる。
 * 各行に、前月（日ごとは前日）の順位からの変動と、店舗のチャネル（全店舗なら BOSS と Amazon）の欠品日数を出す。品番の欠品日数は、
 * 欠品したSKUの欠品日数の合計（K-095）。広い画面では、左に上位10品番・その他の品番・未登録のSKUの割合の円グラフ、右に順位の一覧を並べ、
 * 円グラフに出した品番は一覧にも同じ色の印を付ける（K-097・K-098）。順位の一覧は10位ずつのページに分けて切り替える（K-100）。
 */

const props = defineProps<{
    /** 開いている欄。null なら閉じている */
    target: { period: string; label: string; mall: StoreSales["mall"] | null; storeLabel: string } | null;
    unit: SalesUnit;
    products: StoreSalesProducts;
    measure: ActualMeasure;
}>();
const emit = defineEmits<{ close: [] }>();

const ORDERS: { value: ActualMeasure; label: string }[] = [
    { value: "amount", label: "金額順" },
    { value: "quantity", label: "販売数順" },
];

const order = ref<ActualMeasure>(props.measure);
const result = shallowRef<StoreRankingResponse | null>(null);
const isLoading = ref(false);
const hasError = ref(false);
const root = useTemplateRef<HTMLElement>("root");

/** 順位の一覧は10位ずつのページに分ける（K-100）。欄・並び順を変えて読み込み直したら1ページ目に戻す。 */
const PAGE_SIZE = 10;
const page = ref(0);

async function load(): Promise<void> {
    const target = props.target;
    if (!target) {
        return;
    }
    const requested = order.value;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchStoreRanking(props.unit, target.period, target.mall, props.products, requested);
        // 読み込み中に欄や並び順を変えたときは、古い方の結果を表示しない
        if (props.target === target && order.value === requested) {
            result.value = response;
            page.value = 0;
        }
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

watch(
    () => props.target,
    async (target, previous) => {
        if (!target) {
            result.value = null;
            return;
        }
        if (!previous) {
            order.value = props.measure;
        }
        void load();
        await nextTick();
        // jsdom には scrollIntoView が無い
        root.value?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
    },
    { immediate: true },
);
watch(order, () => void load());

const pageCount = computed(() => Math.ceil((result.value?.data.items.length ?? 0) / PAGE_SIZE));
const pageItems = computed(() => result.value?.data.items.slice(page.value * PAGE_SIZE, (page.value + 1) * PAGE_SIZE) ?? []);
const pageLabel = (index: number): string => {
    const count = result.value?.data.items.length ?? 0;
    return `${index * PAGE_SIZE + 1}〜${Math.min(count, (index + 1) * PAGE_SIZE)}位`;
};

const valueOf = (row: { quantity: number; amount: number }): number => (order.value === "amount" ? row.amount : row.quantity);
const format = (value: number): string => (order.value === "amount" ? formatYen(value) : `${formatQuantity(value)}個`);
const totalValue = computed(() => (result.value ? valueOf(result.value.meta.total) : 0));
const share = (row: { quantity: number; amount: number }): number => (totalValue.value > 0 ? valueOf(row) / totalValue.value : 0);
const formatShare = (value: number): string => `${(value * 100).toFixed(1)}%`;
const color = computed(() => (props.target?.mall ? STORE_COLORS[props.target.mall] : "#57534e"));
/** 売れ方が分かるよう、金額順なら販売数、販売数順なら金額を小さく添える。 */
const subOf = (row: { quantity: number; amount: number }): string => (order.value === "amount" ? `${formatQuantity(row.quantity)}個` : formatYen(row.amount));

/** 円グラフの区分（並び順で見ている金額か販売数の割合）。 */
const segments = computed(() =>
    result.value
        ? donutSegments(
              result.value.data.items.map((row) => ({ key: row.item_no, label: row.item_no, value: valueOf(row) })),
              valueOf(result.value.data.unregistered),
              totalValue.value,
          )
        : [],
);
/** 円グラフに出した品番の色の印（出していなければ null）。 */
function segmentSwatch(itemNo: string): Record<string, string> | null {
    const segment = segments.value.find((item) => !item.isGroup && item.key === itemNo);
    return segment ? swatchStyle(segment) : null;
}

const previousLabel = computed(() => (props.unit === "day" ? "前日" : "前月"));

/** 前の期間の順位からの変動。前の期間の販売実績が無ければ出さない。 */
function rankChange(row: { rank: number; previous_rank: number | null }): { text: string; tone: "up" | "down" | "same" | "new"; title: string } | null {
    if (!result.value?.meta.previous.available) {
        return null;
    }
    if (row.previous_rank === null) {
        return { text: "新", tone: "new", title: `${previousLabel.value}は売れていません` };
    }
    const diff = row.previous_rank - row.rank;
    const title = `${previousLabel.value}は${row.previous_rank}位`;
    return diff > 0 ? { text: `▲${diff}`, tone: "up", title } : diff < 0 ? { text: `▼${-diff}`, tone: "down", title } : { text: "→", tone: "same", title };
}

const CHANGE_CLASSES = { up: "text-emerald-700", down: "text-amber-700", same: "text-stone-400", new: "rounded bg-sky-50 px-1 text-sky-700 ring-1 ring-sky-200" } as const;

/**
 * 欠品日数の札（K-095・K-096）。欠品アイコンと「4日」だけを出し（全店舗なら BOSS・Amazon を添える）、欠品したSKUの数や、在庫の記録が月の途中から
 * あるときに確かめられた日数は、マウスを乗せたときの説明に出す。品番の日数は、欠品したSKUの欠品日数の合計。在庫の記録の無いときは「—」。
 */
function stockoutBadge(stockout: (RankingStockout & { skus?: number }) | undefined, label: string | null): { text: string; isOut: boolean; title: string } {
    const prefix = label === null ? "" : `${label} `;
    if (!stockout || stockout.days === null) {
        return { text: `${prefix}—`, isOut: false, title: `${prefix}欠品：在庫の記録が無いため、判定できません` };
    }
    const details = [
        stockout.skus !== undefined ? (stockout.skus > 0 ? `欠品したSKU ${stockout.skus}件の欠品日数の合計` : "欠品したSKUはありません") : null,
        stockout.known_days < stockout.period_days ? `在庫の記録で確認できたのは${stockout.period_days}日のうち${stockout.known_days}日` : null,
    ].filter((detail): detail is string => detail !== null);
    return { text: `${prefix}${stockout.days}日`, isOut: stockout.days > 0, title: [`${prefix}欠品 ${stockout.days}日`, ...details].join("。") };
}

/** 行の欠品の札（店舗ならそのチャネル、全店舗なら BOSS と Amazon）。 */
function stockoutBadges(row: { stockouts: Partial<Record<"boss" | "amazon", RankingStockout & { skus?: number }>> }) {
    const channels = result.value?.meta.stockout_channels ?? [];
    return channels.map((channel) => ({ key: channel.channel, ...stockoutBadge(row.stockouts[channel.channel], channels.length > 1 ? channel.label : null) }));
}
</script>

<template>
    <section v-if="target" ref="root" aria-labelledby="ranking-heading" class="scroll-mt-4 overflow-hidden rounded-xl border border-stone-200 bg-white" data-testid="ranking-panel">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
            <div class="flex flex-col gap-1">
                <h2 id="ranking-heading" class="flex items-center gap-2 text-base font-bold text-stone-900">
                    <span class="size-3 rounded-sm" :style="{ backgroundColor: color }" aria-hidden="true" />
                    {{ target.storeLabel }}・{{ target.label }}の売上上位
                </h2>
                <p class="text-xs text-stone-500 tabular-nums" data-testid="ranking-summary">
                    <template v-if="result">
                        {{ result.meta.from.replaceAll("-", "/") }}{{ result.meta.from === result.meta.to ? "" : `〜${result.meta.to.replaceAll("-", "/")}` }}・{{
                            products === "all" ? "全商品" : "対象品番"
                        }}・合計 {{ formatYen(result.meta.total.amount) }}（{{ formatQuantity(result.meta.total.quantity) }}個）
                    </template>
                    <template v-else>読み込み中…</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="並び順">
                    <button
                        v-for="option in ORDERS"
                        :key="option.value"
                        type="button"
                        class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                        :class="order === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                        :aria-pressed="order === option.value"
                        :data-testid="`ranking-order-${option.value}`"
                        @click="order = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <button
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="売上上位を閉じる"
                    @click="emit('close')"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
                </button>
            </div>
        </header>

        <div v-if="hasError" class="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <p class="text-sm text-stone-600">売上上位を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="load">再読み込み</button>
        </div>
        <div v-else-if="!result" class="px-6 py-12 text-center text-sm text-stone-500">読み込み中…</div>
        <div v-else-if="result.meta.total.quantity === 0" class="px-6 py-12 text-center text-sm text-stone-500" data-testid="ranking-empty">この期間の販売実績はありません。</div>
        <!-- 広い画面では、左に円グラフ、右に順位の一覧を並べる -->
        <div v-else class="grid transition-opacity lg:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]" :class="isLoading ? 'opacity-50' : ''" :aria-busy="isLoading">
            <div class="border-stone-200 px-5 py-5 max-lg:border-b lg:border-r" data-testid="ranking-chart-column">
                <h3 class="mb-3 text-sm font-semibold text-stone-900">品番ごとの割合</h3>
                <RankingDonut :segments="segments" :total="totalValue" :format="format" :total-label="order === 'amount' ? '合計金額' : '合計販売数'" />
            </div>
            <section class="flex min-w-0 flex-col" aria-labelledby="ranking-items">
                <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-4 pb-2">
                    <h3 id="ranking-items" class="text-sm font-semibold text-stone-900">品番 上位{{ result.data.items.length }}</h3>
                    <nav v-if="pageCount > 1" class="flex items-center gap-1" aria-label="順位のページ" data-testid="ranking-pager">
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-md text-stone-500 hover:bg-stone-100 hover:text-stone-900 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent"
                            :disabled="page === 0"
                            aria-label="前の10位"
                            data-testid="ranking-page-previous"
                            @click="page--"
                        >
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 3.5 5.5 8l4.5 4.5" />
                            </svg>
                        </button>
                        <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75">
                            <button
                                v-for="index in pageCount"
                                :key="index"
                                type="button"
                                class="inline-flex h-7 items-center rounded-[7px] px-2.5 text-[12px] tabular-nums"
                                :class="page === index - 1 ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                                :aria-current="page === index - 1 ? 'page' : undefined"
                                :data-testid="`ranking-page-${index}`"
                                @click="page = index - 1"
                            >
                                {{ pageLabel(index - 1) }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-md text-stone-500 hover:bg-stone-100 hover:text-stone-900 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent"
                            :disabled="page >= pageCount - 1"
                            aria-label="次の10位"
                            data-testid="ranking-page-next"
                            @click="page++"
                        >
                            <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m6 3.5 4.5 4.5L6 12.5" />
                            </svg>
                        </button>
                    </nav>
                </div>
                <ol class="flex flex-col pb-2" :start="page * PAGE_SIZE + 1" data-testid="ranking-items">
                    <li v-for="row in pageItems" :key="row.item_no" class="flex items-center gap-3 px-5 py-2" data-testid="ranking-item">
                        <span class="flex w-7 shrink-0 flex-col items-end leading-tight tabular-nums">
                            <span class="text-[12px] text-stone-500">{{ row.rank }}</span>
                            <span v-if="rankChange(row)" class="text-[10px] font-medium" :class="CHANGE_CLASSES[rankChange(row)!.tone]" :title="rankChange(row)!.title" data-testid="rank-change">{{
                                rankChange(row)!.text
                            }}</span>
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <span
                                    v-if="segmentSwatch(row.item_no)"
                                    class="size-2.5 shrink-0 rounded-sm"
                                    :style="segmentSwatch(row.item_no)!"
                                    aria-hidden="true"
                                    data-testid="ranking-item-swatch"
                                />
                                <span class="shrink-0 font-mono text-sm font-medium text-stone-900">{{ row.item_no }}</span>
                                <span class="min-w-0 truncate rounded-full border border-stone-200 px-2 py-px text-[11px] text-stone-600">{{ row.brand }}・{{ row.category }}</span>
                                <span class="shrink-0 text-[11px] text-stone-400 tabular-nums">{{ row.sku_count }} SKU</span>
                            </span>
                            <span class="h-1 w-full rounded-full bg-stone-100"><span class="block h-full rounded-full" :style="{ width: `${share(row) * 100}%`, backgroundColor: color }" /></span>
                            <span class="flex flex-wrap gap-1">
                                <span
                                    v-for="badge in stockoutBadges(row)"
                                    :key="badge.key"
                                    class="inline-flex items-center gap-1 rounded px-1.5 py-px text-[10.5px] tabular-nums"
                                    :class="badge.isOut ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-stone-50 text-stone-500'"
                                    :title="badge.title"
                                    data-testid="stockout-badge"
                                    ><StockoutIcon /><span>{{ badge.text }}</span></span
                                >
                            </span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end tabular-nums">
                            <span class="text-sm text-stone-900">{{ format(valueOf(row)) }}</span>
                            <span class="text-[11px] text-stone-500">{{ formatShare(share(row)) }}・{{ subOf(row) }}</span>
                        </span>
                    </li>
                </ol>
                <p v-if="result.data.items.length === 0" class="px-5 pb-4 text-xs text-stone-500">品番に登録されたSKUの販売実績はありません。</p>
                <p v-if="result.data.unregistered.sku_count > 0" class="mt-auto border-t border-stone-100 px-5 py-2.5 text-xs text-stone-500 tabular-nums" data-testid="ranking-unregistered">
                    CrossWalkerに未登録のSKU（{{ formatQuantity(result.data.unregistered.sku_count) }} SKU）：{{ format(valueOf(result.data.unregistered)) }}・{{
                        formatShare(share(result.data.unregistered))
                    }}。品番の順位には含めていません
                </p>
            </section>
        </div>
        <div class="flex flex-col gap-0.5 border-t border-stone-200 px-5 py-2.5 text-[11px] text-stone-500" data-testid="ranking-notes">
            <p>割合は、この店舗・期間の合計（{{ order === "amount" ? "金額" : "販売数" }}）に対する割合です。</p>
            <p v-if="result?.meta.previous.available">
                順位の変動は{{ previousLabel }}（{{ result.meta.previous.from.replaceAll("-", "/")
                }}{{ result.meta.previous.from === result.meta.previous.to ? "" : `〜${result.meta.previous.to.replaceAll("-", "/")}` }}）の順位との比較です（▲上がった・▼下がった・新：{{
                    previousLabel
                }}は売れていない）。
            </p>
            <p v-else-if="result" data-testid="ranking-no-previous">{{ previousLabel }}の販売実績を取り込んでいないため、順位の変動は出していません。</p>
            <p>
                欠品日数は、{{
                    target.mall === null ? "BOSS・Amazonそれぞれ" : "この店舗のチャネル"
                }}の在庫の区分の合計が0以下だった日数です（在庫を取得していない日は直前の状態を引き継ぎます）。品番は、欠品したSKUの欠品日数の合計です。
            </p>
        </div>
    </section>
</template>
