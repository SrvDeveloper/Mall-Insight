import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";
import { onUnauthorized } from "@/api/client";
import { afterLoginLocation } from "@/router/afterLogin";
import AppLayout from "@/layouts/AppLayout.vue";
import DemandForecastView from "@/views/DemandForecastView.vue";
import InboundPlanView from "@/views/InboundPlanView.vue";
import InventoryTrendView from "@/views/InventoryTrendView.vue";
import InventoryImportView from "@/views/InventoryImportView.vue";
import ItemListView from "@/views/ItemListView.vue";
import LoginView from "@/views/LoginView.vue";
import SalesImportView from "@/views/SalesImportView.vue";
import TargetItemSelectionView from "@/views/TargetItemSelectionView.vue";
import { useAuthStore } from "@/stores/auth";

declare module "vue-router" {
    interface RouteMeta {
        title?: string;
        /** ログインしていなくても開ける画面 */
        public?: boolean;
    }
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
            { path: "target-items", name: "target-items", component: TargetItemSelectionView, meta: { title: "対象品番" } },
            { path: "forecasts", name: "forecasts", component: DemandForecastView, meta: { title: "需要予測" } },
            { path: "inbound-plans", name: "inbound-plans", component: InboundPlanView, meta: { title: "入荷予定" } },
            { path: "inventory-trends", name: "inventory-trends", component: InventoryTrendView, meta: { title: "在庫推移" } },
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
