<script setup lang="ts">
import { computed, nextTick, reactive, ref } from "vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import AppIcon from "@/components/icons/AppIcon.vue";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseSelect, { type SelectOption } from "@/components/ui/BaseSelect.vue";

// --- 表示の前提（根拠表示用のメタ情報） ---------------------------------
const baseDate = "2026-08-31";
const selectionVersion = "選定v2026-09-01";
const forecastVersion = "予測v2026-09-01-003";
const calculatedAt = ref("2026-09-01 09:05");
const isCalculating = ref(false);

function recalculate(): void {
    if (isCalculating.value) return;

    isCalculating.value = true;
    window.setTimeout(() => {
        calculatedAt.value = new Date().toLocaleString("ja-JP", { hour12: false });
        isCalculating.value = false;
    }, 500);
}

function monthLabels(count: number): { key: string; label: string }[] {
    const [y, m] = [2026, 9];
    return Array.from({ length: count }, (_, i) => {
        const date = new Date(y, m - 1 + i, 1);
        const year = date.getFullYear();
        const month = date.getMonth() + 1;
        return { key: `${year}-${String(month).padStart(2, "0")}`, label: `${String(year).slice(2)}/${month}` };
    });
}
const months = monthLabels(12);

// 欠品警告・過剰在庫警告は、基準日時点の在庫ではなく「基準日から6か月後」時点の
// シミュレーション結果（月末在庫・未充足需要）で判定する。調達リードタイム等を踏まえ、
// 直近の在庫状況ではなく半年先を見越して警告を出す運用方針のため。
const WARNING_CHECK_MONTH_INDEX = 5;

// 調達マスタの簡素化方針により、調達リードタイムと安全在庫日数はSKUごとに持たず全SKU共通の固定値とする。
// 発注ロット・最小発注数・仕入先はデータとして管理せずメモ運用とするため、本画面の計算対象に含めない。
const LEAD_TIME_DAYS = 30;
const SAFETY_STOCK_DAYS = 10;

// --- ダミーデータ：売上上位20品番として確定済みの対象品番・SKU -----------
// システム需要予測はSKU単位で1本だけ保持し、モール（倉庫区分）別には保持しない
// （要件定義書 FR-011、在庫利用要件 第3.3節：倉庫区分別需要は按分比率から必要時に算出するもので別建て保存しない）。
// 一方、現在庫はSKU×倉庫区分単位で実在するデータのため（FR-050）、Amazon・BOSS・ストック・フリーの
// 基準日時点の内訳をSKUごとに保持し、ホバー時の参考情報として表示する（月次推移の計算には用いない）。
// 商品名はマスタに存在しないため保持しない。品番・カテゴリー・ブランド名で識別する。
interface SkuDefinition {
    sku: string;
    amazonStock: number | null; // 基準日時点のAmazon在庫（現在庫の内訳、参考表示専用）
    bossStock: number | null; // 基準日時点のBOSS在庫（現在庫の内訳、参考表示専用）
    ecStock: number | null; // ECストック：EC出荷用に確保した社内予備在庫（販売では減らない）
    freeStock: number | null; // フリー在庫：社内の自由に使える在庫（販売では減らない）
    demand: number[]; // システム需要予測（SKU単位、月別12か月）
    inbound: number[]; // 状態が在庫試算対象である発注・入荷予定のSKU明細から集計した月別入荷予定数
}

interface ProductGroup {
    productCode: string;
    category: string;
    brand: string;
    skus: SkuDefinition[];
}

const productGroups: ProductGroup[] = [
    {
        productCode: "fisi-05",
        category: "老眼鏡",
        brand: "SHIORI",
        skus: [
            {
                sku: "fisi-05-1-10",
                amazonStock: 520,
                bossStock: 980,
                ecStock: 150,
                freeStock: 300,
                demand: [440, 448, 200, 220, 538, 565, 510, 460, 436, 420, 410, 410],
                inbound: [0, 0, 300, 80, 0, 0, 0, 0, 0, 0, 0, 0],
            },
            {
                sku: "fisi-05-1-15",
                amazonStock: 300,
                bossStock: 950,
                ecStock: 90,
                freeStock: 200,
                demand: [120, 130, 140, 150, 160, 170, 180, 190, 200, 210, 220, 230],
                inbound: [0, 0, 0, 0, 250, 0, 50, 0, 0, 0, 0, 0],
            },
            {
                sku: "fisi-05-1-20",
                amazonStock: 100,
                bossStock: 40,
                ecStock: 0,
                freeStock: 0,
                demand: [120, 130, 140, 150, 160, 170, 180, 190, 200, 210, 220, 230],
                inbound: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            },
        ],
    },
    {
        productCode: "P-002",
        category: "生活雑貨",
        brand: "URBAN CRAFT",
        skus: [
            {
                sku: "P002-BLK-FREE",
                amazonStock: 2200,
                bossStock: 60,
                ecStock: 40,
                freeStock: 120,
                demand: [218, 220, 226, 233, 242, 249, 234, 224, 217, 213, 211, 211],
                inbound: [0, 0, 0, 0, 60, 300, 0, 0, 0, 0, 0, 0],
            },
        ],
    },
    {
        productCode: "P-003",
        category: "生活雑貨",
        brand: "URBAN CRAFT",
        skus: [
            {
                sku: "P003-BLK-FREE",
                amazonStock: 500,
                bossStock: 60,
                ecStock: 40,
                freeStock: 120,
                demand: [218, 220, 226, 233, 242, 249, 234, 224, 217, 213, 211, 211],
                inbound: [0, 0, 0, 0, 60, 300, 0, 0, 0, 0, 0, 0],
            },
        ],
    },
];

interface MonthResult {
    key: string;
    label: string;
    demand: number;
    inbound: number;
    monthEnd: number;
    shortfall: number;
    displayValue: number; // マイナス=未充足需要（欠品数量）をそのまま表示、プラス=月末在庫
}

interface SeriesResult {
    forecastUnavailable: boolean;
    partial: boolean;
    series: MonthResult[];
    shortageApproxLabel: string | null;
    warningLevel: "none" | "shortage" | "excess";
    recommendedOrder: number | null;
}

function buildResult(input: { openingStock: number | null; demand: number[]; inbound: number[]; partial: boolean }): SeriesResult {
    if (input.openingStock === null || input.demand.length === 0) {
        return { forecastUnavailable: true, partial: input.partial, series: [], shortageApproxLabel: null, warningLevel: "none", recommendedOrder: null };
    }

    let stock = input.openingStock;
    const series: MonthResult[] = months.map((m, i) => {
        const demand = input.demand[i] ?? 0;
        const inbound = input.inbound[i] ?? 0;
        const opening = stock;
        const monthEnd = Math.max(0, opening + inbound - demand);
        const shortfall = Math.max(0, demand - opening - inbound);
        stock = monthEnd;
        return { key: m.key, label: m.label, demand, inbound, monthEnd, shortfall, displayValue: shortfall > 0 ? -shortfall : monthEnd };
    });

    const avgDailyDemand = input.demand.reduce((a, b) => a + b, 0) / input.demand.length / 30;

    const safetyStock = avgDailyDemand * SAFETY_STOCK_DAYS;
    const leadTimeMonths = Math.max(1, Math.ceil(LEAD_TIME_DAYS / 30));
    const leadTimeDemand = input.demand.slice(0, leadTimeMonths).reduce((a, b) => a + b, 0);
    const scheduledInbound = input.inbound.slice(0, leadTimeMonths).reduce((a, b) => a + b, 0);
    const recommendedOrder = Math.max(0, Math.round(leadTimeDemand + safetyStock - input.openingStock - scheduledInbound));

    const checkMonth = series[WARNING_CHECK_MONTH_INDEX];
    const isShortageAtCheckMonth = checkMonth.shortfall > 0;
    const isExcessAtCheckMonth = !isShortageAtCheckMonth && avgDailyDemand > 0 && checkMonth.monthEnd > avgDailyDemand * 30 * 4;

    return {
        forecastUnavailable: false,
        partial: input.partial,
        series,
        shortageApproxLabel: null,
        warningLevel: isShortageAtCheckMonth ? "shortage" : isExcessAtCheckMonth ? "excess" : "none",
        recommendedOrder,
    };
}

interface SkuRow extends SkuDefinition {
    result: SeriesResult;
}
interface ProductRow extends ProductGroup {
    skuRows: SkuRow[];
}

const productRows = computed<ProductRow[]>(() =>
    productGroups.map((group) => ({
        ...group,
        skuRows: group.skus.map((sku) => {
            // 月次推移の計算にはAmazon+BOSSの現在庫合計のみを用いる（ストック・フリーは販売対象外のため含めない）。
            // 反映対象入荷予定数は、発注・入荷予定のSKU明細から月別に集計する。このサンプル画面ではSKU合計を表示するため、
            // FR-060（月末在庫=月初在庫+反映対象入荷予定-需要予測）の通りそのまま組み込む。
            const openingStock = (sku.amazonStock ?? 0) + (sku.bossStock ?? 0);
            const result = buildResult({ openingStock, demand: sku.demand, inbound: sku.inbound, partial: false });

            return { ...sku, result };
        }),
    })),
);

// --- ホバーでツールチップ表示 -----------------------------------------------
// SKU欄：基準日時点の現在庫（在庫総数・Amazon・BOSS・ストック・フリー）の内訳を表示。
// 月欄：その月の販売数・入荷予定数のみを表示（現在庫の内訳は月によって変動しないため対象外）。
const tooltipEl = ref<HTMLElement | null>(null);
const tooltip = reactive({
    visible: false,
    mode: "month" as "sku" | "month",
    left: 0,
    top: 0,
    monthLabel: "",
    salesText: "",
    replenishmentText: "",
    totalText: "",
    amazonText: "",
    bossText: "",
    ecStockText: "",
    freeStockText: "",
});

function stockText(value: number | null): string {
    return value === null ? "ー" : value.toLocaleString();
}

// ツールチップの内容によって高さ・幅が変わるため、描画後に実測して画面外にはみ出さない位置へ補正する。
function positionTooltip(event: MouseEvent): void {
    const offset = 14;
    const margin = 8;
    tooltip.left = event.clientX + offset;
    tooltip.top = event.clientY + offset;

    nextTick(() => {
        const el = tooltipEl.value;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        if (event.clientY + offset + rect.height > window.innerHeight - margin) {
            tooltip.top = Math.max(margin, event.clientY - offset - rect.height);
        }
        if (event.clientX + offset + rect.width > window.innerWidth - margin) {
            tooltip.left = Math.max(margin, event.clientX - offset - rect.width);
        }
    });
}

// ホバーしてすぐ表示すると意図しない通過でも出てしまうため、0.2秒とどまってから表示する。
const HOVER_DELAY_MS = 200;
let hoverTimer: ReturnType<typeof setTimeout> | null = null;

function clearHoverTimer(): void {
    if (hoverTimer !== null) {
        clearTimeout(hoverTimer);
        hoverTimer = null;
    }
}

function onSkuHover(event: MouseEvent, r: SkuRow): void {
    if (tooltip.visible && tooltip.mode === "sku") {
        positionTooltip(event);
        return;
    }
    clearHoverTimer();
    hoverTimer = setTimeout(() => {
        tooltip.visible = true;
        tooltip.mode = "sku";
        tooltip.amazonText = stockText(r.amazonStock);
        tooltip.bossText = stockText(r.bossStock);
        tooltip.ecStockText = stockText(r.ecStock);
        tooltip.freeStockText = stockText(r.freeStock);
        const total = (r.amazonStock ?? 0) + (r.bossStock ?? 0) + (r.ecStock ?? 0) + (r.freeStock ?? 0);
        tooltip.totalText = total.toLocaleString();
        positionTooltip(event);
    }, HOVER_DELAY_MS);
}

function onMonthHover(event: MouseEvent, r: SkuRow, index: number, monthLabel: string): void {
    if (tooltip.visible && tooltip.mode === "month") {
        positionTooltip(event);
        return;
    }
    clearHoverTimer();
    hoverTimer = setTimeout(() => {
        tooltip.visible = true;
        tooltip.mode = "month";
        tooltip.monthLabel = monthLabel;
        if (r.result.forecastUnavailable) {
            tooltip.salesText = "予測不能";
            tooltip.replenishmentText = "ー";
        } else {
            const month = r.result.series[index];
            tooltip.salesText = month.demand.toLocaleString();
            tooltip.replenishmentText = month.inbound > 0 ? `+${month.inbound.toLocaleString()}` : "0";
        }
        positionTooltip(event);
    }, HOVER_DELAY_MS);
}

function hideTooltip(): void {
    clearHoverTimer();
    tooltip.visible = false;
}

// --- 絞り込み --------------------------------------------------------------
const keyword = ref("");
const category = ref("all");

const categoryOptions: SelectOption[] = [
    { label: "すべて", value: "all" },
    { label: "化粧品", value: "化粧品" },
    { label: "生活雑貨", value: "生活雑貨" },
];

const expanded = reactive<Record<string, boolean>>(Object.fromEntries(productGroups.map((group) => [group.productCode, true])));
function toggleExpand(group: ProductRow): void {
    expanded[group.productCode] = !expanded[group.productCode];
}

const filteredGroups = computed(() =>
    productRows.value.filter((group) => {
        if (category.value !== "all" && group.category !== category.value) return false;
        if (keyword.value && !group.skuRows.some((r) => `${group.productCode}${r.sku}`.includes(keyword.value))) return false;

        return true;
    }),
);

function warningCount(group: ProductRow): number {
    return group.skuRows.filter((r) => r.result.warningLevel === "shortage").length;
}

function cellClass(value: number, result: SeriesResult): string {
    if (value < 0) return "bg-red-50 text-red-700 font-semibold";
    if (result.warningLevel === "excess") return "bg-blue-50 text-blue-700";
    if (value === 0) return "bg-amber-50 text-amber-700";
    return "text-slate-700";
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-[22px] font-bold text-slate-900">12か月在庫推移</h1>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[12px] font-semibold text-slate-600">サンプルデータ</span>
                </div>
                <p class="mt-1 text-[14px] text-slate-500">画面を開いた時点の最新の予測・在庫・反映対象の発注・入荷予定から、12か月先までの在庫推移を自動算出します。</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-1 text-[13px] font-semibold text-primary-700">
                    <AppIcon name="trending-up" :size="15" />
                    需要値はシステム需要予測に基づく
                </span>
                <BaseButton variant="outline" size="sm" :disabled="isCalculating" @click="recalculate">
                    <AppIcon name="sync" :size="14" :class="isCalculating ? 'animate-spin' : ''" />
                    {{ isCalculating ? "再計算中…" : "最新データで再計算" }}
                </BaseButton>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-[13px] text-slate-500">
            <span
                >基準日 <span class="font-semibold text-slate-700">{{ baseDate }}</span></span
            >
            <span
                >選定バージョン <span class="font-semibold text-slate-700">{{ selectionVersion }}</span></span
            >
            <span
                >予測バージョン <span class="font-semibold text-slate-700">{{ forecastVersion }}</span></span
            >
            <span
                >算出日時 <span class="font-semibold text-slate-700">{{ calculatedAt }}</span></span
            >
            <span class="ml-auto inline-flex items-center gap-1 font-semibold text-slate-600"><AppIcon name="layers" :size="15" />改善モード（現行Excel再現モードではない）</span>
        </div>

        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3.5">
            <BaseInput v-model="keyword" label="品番・SKU" placeholder="検索" icon="search" class="w-44" />
            <BaseSelect v-model="category" label="カテゴリー" :options="categoryOptions" class="w-34" />
        </div>

        <div v-for="group in filteredGroups" :key="group.productCode" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="toggleExpand(group)">
                <div class="flex min-w-0 items-center gap-3">
                    <AppIcon name="chevron-right" :size="14" class="shrink-0 text-slate-400 transition-transform" :class="expanded[group.productCode] ? 'rotate-90' : ''" />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[16px] font-bold text-slate-900">{{ group.productCode }}</span>
                            <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[12px] font-semibold text-slate-500">{{ group.category }}</span>
                            <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[12px] font-semibold text-slate-500">{{ group.brand }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-[13px] text-slate-500">SKU {{ group.skuRows.length }}件</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    <span v-if="warningCount(group) > 0" class="rounded-full bg-red-50 px-2 py-0.5 text-[12px] font-semibold text-red-600">欠品警告 {{ warningCount(group) }}件</span>
                    <span v-else class="rounded-full bg-emerald-50 px-2 py-0.5 text-[12px] font-semibold text-emerald-600">欠品警告なし</span>
                </div>
            </button>

            <div v-show="expanded[group.productCode]" class="overflow-x-auto border-t border-slate-100">
                <table class="w-full table-fixed border-collapse text-left">
                    <colgroup>
                        <col class="w-[16%]" />
                        <col v-for="m in months" :key="m.key" class="w-[6%]" />
                        <col class="w-[12%]" />
                    </colgroup>
                    <thead class="bg-slate-50 text-[14px] font-semibold text-slate-500">
                        <tr>
                            <th class="sticky left-0 z-10 border-r border-b border-slate-200 bg-slate-50 px-4 py-2.5">SKU</th>
                            <th
                                v-for="(m, index) in months"
                                :key="m.key"
                                class="border-r border-b border-slate-200 px-2 py-2.5 text-right"
                                :class="index === WARNING_CHECK_MONTH_INDEX ? 'bg-yellow-100 text-yellow-800' : ''"
                            >
                                {{ m.label }}
                            </th>
                            <th class="border-b border-slate-200 px-3 py-2.5">警告</th>
                        </tr>
                    </thead>
                    <tbody class="text-[15px]">
                        <tr v-for="r in group.skuRows" :key="r.sku" class="border-b border-slate-200 hover:bg-slate-50/70">
                            <td
                                class="sticky left-0 z-10 cursor-help border-r border-slate-200 bg-white px-4 py-2.5"
                                @mouseenter="onSkuHover($event, r)"
                                @mousemove="onSkuHover($event, r)"
                                @mouseleave="hideTooltip"
                            >
                                <p class="font-semibold text-slate-700">{{ r.sku }}</p>
                            </td>

                            <template v-if="r.result.forecastUnavailable">
                                <td :colspan="12" class="border-r border-slate-200 px-2 py-2.5 text-center text-[14px] font-medium text-slate-400">予測不能（対象期間の販売実績なし）</td>
                            </template>
                            <template v-else>
                                <td
                                    v-for="(month, index) in r.result.series"
                                    :key="month.key"
                                    class="cursor-help border-r border-slate-200 px-2 py-2.5 text-right tabular-nums"
                                    :class="cellClass(month.displayValue, r.result)"
                                    @mouseenter="onMonthHover($event, r, index, month.label)"
                                    @mousemove="onMonthHover($event, r, index, month.label)"
                                    @mouseleave="hideTooltip"
                                >
                                    {{ month.displayValue.toLocaleString() }}
                                </td>
                            </template>

                            <td class="whitespace-nowrap px-3 py-2.5">
                                <span v-if="r.result.warningLevel === 'shortage'" class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[13px] font-semibold text-red-600">
                                    <AppIcon name="warning" :size="14" />欠品警告
                                </span>
                                <span v-else-if="r.result.warningLevel === 'excess'" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[13px] font-semibold text-blue-600">
                                    <AppIcon name="inventory_2" :size="14" />過剰在庫
                                </span>
                                <span v-else-if="!r.result.forecastUnavailable" class="text-slate-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p v-if="filteredGroups.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white py-16 text-center text-[14px] text-slate-400">条件に一致する対象品番がありません。</p>

        <Transition name="tooltip-fade">
            <div
                v-if="tooltip.visible"
                ref="tooltipEl"
                class="pointer-events-none fixed z-50 min-w-36 rounded-lg bg-slate-900 px-3 py-2 text-[13px] text-white shadow-lg"
                :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }"
            >
                <template v-if="tooltip.mode === 'sku'">
                    <p class="font-semibold text-slate-300">現在庫の内訳（基準日時点）</p>
                    <p class="mt-1 flex items-center justify-between gap-4">
                        <span class="text-slate-400">在庫総数</span><span class="font-semibold tabular-nums">{{ tooltip.totalText }}</span>
                    </p>
                    <p class="mt-1 flex items-center justify-between gap-4 border-t border-slate-700 pt-1">
                        <span class="text-slate-400">Amazon</span><span class="font-semibold tabular-nums">{{ tooltip.amazonText }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-4">
                        <span class="text-slate-400">BOSS</span><span class="font-semibold tabular-nums">{{ tooltip.bossText }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-4">
                        <span class="text-slate-400">ストック</span><span class="font-semibold tabular-nums">{{ tooltip.ecStockText }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-4">
                        <span class="text-slate-400">フリー</span><span class="font-semibold tabular-nums">{{ tooltip.freeStockText }}</span>
                    </p>
                </template>
                <template v-else>
                    <p class="font-semibold text-slate-300">{{ tooltip.monthLabel }} の内訳</p>
                    <p class="mt-1 flex items-center justify-between gap-4">
                        <span class="text-slate-400">販売数</span><span class="font-semibold tabular-nums">{{ tooltip.salesText }}</span>
                    </p>
                    <p class="flex items-center justify-between gap-4">
                        <span class="text-slate-400">入荷予定数</span><span class="font-semibold tabular-nums text-emerald-400">{{ tooltip.replenishmentText }}</span>
                    </p>
                </template>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.tooltip-fade-enter-active,
.tooltip-fade-leave-active {
    transition: opacity 0.15s ease;
}
.tooltip-fade-enter-from,
.tooltip-fade-leave-to {
    opacity: 0;
}
</style>
