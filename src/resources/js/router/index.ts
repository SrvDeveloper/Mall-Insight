import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";
import AppLayout from "@/layouts/AppLayout.vue";
import InventoryImportView from "@/views/InventoryImportView.vue";
import ItemListView from "@/views/ItemListView.vue";
import SalesImportView from "@/views/SalesImportView.vue";

declare module "vue-router" {
    interface RouteMeta {
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: "/",
        component: AppLayout,
        children: [
            { path: "", redirect: { name: "items" } },
            { path: "imports/sales", name: "sales-imports", component: SalesImportView, meta: { title: "販売実績の取込" } },
            { path: "imports/inventory", name: "inventory-imports", component: InventoryImportView, meta: { title: "在庫の取得" } },
            { path: "items", name: "items", component: ItemListView, meta: { title: "品番・SKU" } },
        ],
    },
    { path: "/:pathMatch(.*)*", redirect: { name: "items" } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} | Mall Insight` : "Mall Insight";
});

export default router;
