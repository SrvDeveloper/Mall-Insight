<script setup lang="ts">
import AppIcon from "@/components/icons/AppIcon.vue";

const summaryCards = [
    { label: "未対処の取込指摘", value: "7", unit: "件", detail: "エラー 2件・警告 5件", icon: "error", iconClass: "bg-red-50 text-red-600", valueClass: "text-red-600", path: "/imports/issues" },
    {
        label: "在庫データの鮮度",
        value: "4 / 4",
        unit: "区分",
        detail: "すべて許容日数以内",
        icon: "inventory_2",
        iconClass: "bg-emerald-50 text-emerald-600",
        valueClass: "text-slate-900",
        path: "/inventory/lookup",
    },
    {
        label: "欠品警告",
        value: "12",
        unit: "SKU",
        detail: "うち30日以内 4 SKU",
        icon: "warning",
        iconClass: "bg-amber-50 text-amber-600",
        valueClass: "text-amber-600",
        path: "/simulations/shortages",
    },
    {
        label: "当月の確定試算",
        value: "未確定",
        unit: "",
        detail: "最終確定 2026-07-31",
        icon: "task_alt",
        iconClass: "bg-blue-50 text-blue-600",
        valueClass: "text-slate-900",
        path: "/simulations/run",
    },
];

const importStatuses = [
    {
        name: "BOSS受注実績",
        source: "販売実績",
        time: "2026-09-01 08:42",
        target: "2026-08-31まで",
        count: "1,284件",
        status: "正常",
        statusClass: "bg-emerald-50 text-emerald-700",
        dotClass: "bg-emerald-500",
    },
    {
        name: "Amazon全注文レポート",
        source: "販売実績",
        time: "2026-09-01 08:36",
        target: "2026-08-31まで",
        count: "462件",
        status: "警告あり",
        statusClass: "bg-amber-50 text-amber-700",
        dotClass: "bg-amber-500",
    },
    {
        name: "日次在庫CSV",
        source: "商品在庫",
        time: "2026-09-01 08:10",
        target: "基準日 2026-08-31",
        count: "272 SKU",
        status: "正常",
        statusClass: "bg-emerald-50 text-emerald-700",
        dotClass: "bg-emerald-500",
    },
];

const warehouses = [
    { name: "BOSS自社倉庫", date: "2026-08-31", days: "0日", stock: "8,420", status: "最新", warning: false },
    { name: "RFC", date: "2026-08-31", days: "0日", stock: "3,156", status: "最新", warning: false },
    { name: "Amazon自社倉庫", date: "2026-08-31", days: "0日", stock: "1,084", status: "最新", warning: false },
    { name: "FBA", date: "2026-08-29", days: "2日", stock: "5,792", status: "要確認", warning: true },
];

const calculationStatuses = [
    { label: "月次需要予測の更新", note: "予測結果の確認が必要です", status: "確認待ち", icon: "pending", complete: false },
    { label: "在庫試算の実行・確定", note: "今月の試算は未実行です", status: "未着手", icon: "radio_button_unchecked", complete: false },
];

const alerts = [
    { title: "Amazon未対応ASIN", detail: "取込指摘から対象データを確認してください", count: "3件", icon: "link_off", color: "text-red-600 bg-red-50", path: "/imports/issues" },
    { title: "30日以内の欠品予測", detail: "FBAを中心に欠品が見込まれます", count: "4 SKU", icon: "inventory", color: "text-amber-600 bg-amber-50", path: "/simulations/shortages" },
    { title: "在庫取込時の消失SKU", detail: "前回存在したSKUが見つかりません", count: "2件", icon: "scan_delete", color: "text-amber-600 bg-amber-50", path: "/imports/issues" },
    { title: "需要予測不能", detail: "実績不足のSKUを確認してください", count: "1 SKU", icon: "query_stats", color: "text-blue-600 bg-blue-50", path: "/forecasts" },
];
</script>

<template>
    <div class="mx-auto flex w-full max-w-400 flex-col gap-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-[20px] font-bold tracking-tight text-slate-900">ダッシュボード</h1>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-600">サンプルデータ</span>
                </div>
                <p class="mt-1 text-[12.5px] text-slate-500">取込状況と、対応が必要な業務をまとめて確認できます。</p>
            </div>
            <div class="flex items-center gap-1.5 text-[11.5px] text-slate-500">
                <AppIcon name="schedule" :size="15" />
                <span>最終更新 2026-09-01 09:00</span>
            </div>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="主要指標">
            <router-link
                v-for="card in summaryCards"
                :key="card.label"
                :to="card.path"
                class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11.5px] font-medium text-slate-500">{{ card.label }}</p>
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-[25px] font-bold leading-none tracking-tight" :class="card.valueClass">{{ card.value }}</span>
                            <span v-if="card.unit" class="text-[11.5px] font-semibold text-slate-500">{{ card.unit }}</span>
                        </p>
                    </div>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="card.iconClass"><AppIcon :name="card.icon" :size="19" /></span>
                </div>
                <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
                    <span class="truncate text-[11px] text-slate-500">{{ card.detail }}</span>
                    <AppIcon name="arrow_forward" :size="14" class="shrink-0 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500" />
                </div>
            </router-link>
        </section>

        <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]">
            <section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5">
                    <div>
                        <h2 class="text-[13.5px] font-bold text-slate-900">データ取込状況</h2>
                        <p class="mt-0.5 text-[11px] text-slate-500">各データの直近の取込結果</p>
                    </div>
                    <router-link to="/imports/history" class="flex items-center gap-1 text-[11.5px] font-semibold text-primary-700 hover:text-primary-800"
                        >取込履歴を表示<AppIcon name="chevron-right" :size="13"
                    /></router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-170 text-left">
                        <thead class="bg-slate-50 text-[10.5px] font-semibold text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">データ</th>
                                <th class="px-3 py-2.5">最終取込</th>
                                <th class="px-3 py-2.5">対象</th>
                                <th class="px-3 py-2.5 text-right">取込件数</th>
                                <th class="px-4 py-2.5 text-right">結果</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-[11.5px]">
                            <tr v-for="item in importStatuses" :key="item.name" class="hover:bg-slate-50/70">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">{{ item.name }}</p>
                                    <p class="mt-0.5 text-[10.5px] text-slate-400">{{ item.source }}</p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ item.time }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ item.target }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-medium text-slate-700">{{ item.count }}</td>
                                <td class="px-4 py-3 text-right">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10.5px] font-semibold" :class="item.statusClass"
                                        ><span class="h-1.5 w-1.5 rounded-full" :class="item.dotClass" />{{ item.status }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5">
                    <div>
                        <h2 class="text-[13.5px] font-bold text-slate-900">要対応</h2>
                        <p class="mt-0.5 text-[11px] text-slate-500">優先して確認が必要な項目</p>
                    </div>
                    <span class="rounded-full bg-red-50 px-2 py-1 text-[10.5px] font-bold text-red-600">10件</span>
                </div>
                <div class="divide-y divide-slate-100">
                    <router-link v-for="alert in alerts" :key="alert.title" :to="alert.path" class="group flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="alert.color"><AppIcon :name="alert.icon" :size="17" /></span>
                        <span class="min-w-0 flex-1"
                            ><span class="flex items-center justify-between gap-2"
                                ><span class="truncate text-[11.5px] font-semibold text-slate-800">{{ alert.title }}</span
                                ><span class="shrink-0 text-[11px] font-bold text-slate-700">{{ alert.count }}</span></span
                            ><span class="mt-0.5 block truncate text-[10.5px] text-slate-500">{{ alert.detail }}</span></span
                        >
                        <AppIcon name="chevron-right" :size="13" class="shrink-0 text-slate-300 group-hover:text-slate-500" />
                    </router-link>
                </div>
            </section>
        </div>

        <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]">
            <section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5">
                    <div>
                        <h2 class="text-[13.5px] font-bold text-slate-900">倉庫別 在庫鮮度</h2>
                        <p class="mt-0.5 text-[11px] text-slate-500">倉庫区分ごとの在庫基準日と経過日数</p>
                    </div>
                    <router-link to="/inventory/lookup" class="flex items-center gap-1 text-[11.5px] font-semibold text-primary-700 hover:text-primary-800"
                        >在庫を照会<AppIcon name="chevron-right" :size="13"
                    /></router-link>
                </div>
                <div class="grid divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4">
                    <div v-for="warehouse in warehouses" :key="warehouse.name" class="p-4 even:border-slate-100 xl:border-0">
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-[11.5px] font-semibold text-slate-800">{{ warehouse.name }}</p>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[9.5px] font-semibold" :class="warehouse.warning ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{
                                warehouse.status
                            }}</span>
                        </div>
                        <p class="mt-3 text-[16px] font-bold text-slate-900">{{ warehouse.stock }}<span class="ml-1 text-[10.5px] font-medium text-slate-400">点</span></p>
                        <div class="mt-2 flex items-center justify-between text-[10.5px]">
                            <span class="text-slate-500">{{ warehouse.date }}</span
                            ><span :class="warehouse.warning ? 'font-semibold text-amber-600' : 'text-slate-500'">{{ warehouse.days }}経過</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3.5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-[13.5px] font-bold text-slate-900">今月の計算・確定状況</h2>
                            <p class="mt-0.5 text-[11px] text-slate-500">2026年9月度</p>
                        </div>
                        <span class="text-[11.5px] font-bold text-primary-700">0 / 2 完了</span>
                    </div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full w-0 rounded-full bg-primary-600" /></div>
                </div>
                <ol class="divide-y divide-slate-100 px-4">
                    <li v-for="task in calculationStatuses" :key="task.label" class="flex items-start gap-3 py-2.5">
                        <AppIcon :name="task.icon" :size="17" class="mt-0.5 shrink-0" :class="task.complete ? 'text-emerald-600' : 'text-slate-300'" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-[11.5px] font-semibold" :class="task.complete ? 'text-slate-600' : 'text-slate-800'">{{ task.label }}</p>
                                <span class="shrink-0 text-[9.5px] font-semibold" :class="task.complete ? 'text-emerald-600' : 'text-slate-500'">{{ task.status }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-[10.5px] text-slate-400">{{ task.note }}</p>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
    </div>
</template>
