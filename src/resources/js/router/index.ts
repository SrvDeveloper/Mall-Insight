import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteLocationRaw, type RouteRecordRaw } from "vue-router";
import { onUnauthorized } from "@/api/client";
import { afterLoginLocation } from "@/router/afterLogin";
import AppLayout from "@/layouts/AppLayout.vue";
import DemandForecastView from "@/views/DemandForecastView.vue";
import ForecastAccuracyView from "@/views/ForecastAccuracyView.vue";
import SalesEventDetailView from "@/views/SalesEventDetailView.vue";
import SalesEventPeriodView from "@/views/SalesEventPeriodView.vue";
import SalesEventView from "@/views/SalesEventView.vue";
import SeasonalFactorView from "@/views/SeasonalFactorView.vue";
import InboundPlanView from "@/views/InboundPlanView.vue";
import InventoryTrendView from "@/views/InventoryTrendView.vue";
import InventoryImportView from "@/views/InventoryImportView.vue";
import ItemListView from "@/views/ItemListView.vue";
import LoginView from "@/views/LoginView.vue";
import ItemSalesView from "@/views/ItemSalesView.vue";
import SkuSalesView from "@/views/SkuSalesView.vue";
import StoreSalesView from "@/views/StoreSalesView.vue";
import SalesImportView from "@/views/SalesImportView.vue";
import SalesTargetView from "@/views/SalesTargetView.vue";
import StockActualsView from "@/views/StockActualsView.vue";
import TargetItemSelectionView from "@/views/TargetItemSelectionView.vue";
import UnitPriceView from "@/views/UnitPriceView.vue";
import { useAuthStore } from "@/stores/auth";

declare module "vue-router" {
    interface RouteMeta {
        title?: string;
        /** ログインしていなくても開ける画面 */
        public?: boolean;
    }
}

/** 以前の一覧の URL（?event=1&period=2）は、イベントの画面・期間の画面へ移す。 */
function redirectOldSalesEventQuery(to: RouteLocationNormalized): RouteLocationRaw | true {
    const { event, period, ...query } = to.query;
    if (typeof event !== "string" || !/^\d+$/.test(event)) {
        return true;
    }
    return typeof period === "string" && /^\d+$/.test(period)
        ? { name: "sales-event-period", params: { eventId: event, periodId: period }, query }
        : { name: "sales-event", params: { eventId: event }, query };
}

const routes: RouteRecordRaw[] = [
    { path: "/login", name: "login", component: LoginView, meta: { title: "ログイン", public: true } },
    {
        path: "/",
        component: AppLayout,
        children: [
            { path: "", redirect: { name: "items" } },
            { path: "imports/sales", name: "sales-imports", component: SalesImportView, meta: { title: "販売実績の取込" } },
            { path: "imports/inventory", name: "inventory-imports", component: InventoryImportView, meta: { title: "在庫の取得" } },
            { path: "items", name: "items", component: ItemListView, meta: { title: "品番・SKU" } },
            { path: "unit-prices", name: "unit-prices", component: UnitPriceView, meta: { title: "単価" } },
            { path: "target-items", name: "target-items", component: TargetItemSelectionView, meta: { title: "対象品番" } },
            // SKU別売上は品番別売上の下に置き、左メニューの「品番別売上」を選んだままにする
            {
                path: "item-sales",
                children: [
                    { path: "", name: "item-sales", component: ItemSalesView, meta: { title: "品番別売上" } },
                    { path: ":itemNo", name: "sku-sales", component: SkuSalesView, meta: { title: "SKU別売上" } },
                ],
            },
            { path: "store-sales", name: "store-sales", component: StoreSalesView, meta: { title: "店舗売上" } },
            { path: "forecasts", name: "forecasts", component: DemandForecastView, meta: { title: "需要予測" } },
            // イベントの画面と期間の画面はイベントの一覧の下に置き、左メニューの「イベント」を選んだままにする
            {
                path: "sales-events",
                children: [
                    { path: "", name: "sales-events", component: SalesEventView, meta: { title: "イベント" }, beforeEnter: redirectOldSalesEventQuery },
                    { path: ":eventId(\\d+)", name: "sales-event", component: SalesEventDetailView, meta: { title: "イベント" } },
                    { path: ":eventId(\\d+)/periods/:periodId(\\d+)", name: "sales-event-period", component: SalesEventPeriodView, meta: { title: "イベントの期間" } },
                ],
            },
            { path: "seasonal-factors", name: "seasonal-factors", component: SeasonalFactorView, meta: { title: "季節係数" } },
            { path: "forecast-accuracy", name: "forecast-accuracy", component: ForecastAccuracyView, meta: { title: "予測精度" } },
            { path: "sales-targets", name: "sales-targets", component: SalesTargetView, meta: { title: "販売目標" } },
            { path: "inbound-plans", name: "inbound-plans", component: InboundPlanView, meta: { title: "入荷予定" } },
            { path: "inventory-trends", name: "inventory-trends", component: InventoryTrendView, meta: { title: "在庫予測" } },
            { path: "stock-actuals", name: "stock-actuals", component: StockActualsView, meta: { title: "在庫推移" } },
        ],
    },
    { path: "/:pathMatch(.*)*", redirect: { name: "items" } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

/** ログインしていないと、ログイン画面以外は開けない（B-009）。開こうとした画面は、ログインした後に開く。 */
router.beforeEach(async (to) => {
    const auth = useAuthStore();
    await auth.ensureLoaded();
    if (to.meta.public) {
        return auth.user && to.name === "login" ? afterLoginLocation(to) : true;
    }
    if (!auth.user) {
        return { name: "login", query: to.fullPath === "/" ? {} : { redirect: to.fullPath } };
    }
    return true;
});

// 画面を開いている間にログインの有効期限が切れたら、ログイン画面へ移す
onUnauthorized(() => {
    if (useAuthStore().expire()) {
        const current = router.currentRoute.value;
        void router.replace({ name: "login", query: { redirect: current.fullPath } });
    }
});

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} | Mall Insight` : "Mall Insight";
});

export default router;
