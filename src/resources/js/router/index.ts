import { createRouter, createWebHistory, type RouteRecordRaw } from "vue-router";
import { navigation, topLevelPages } from "@/data/navigation";
import { useAuthStore } from "@/stores/auth";
import AppLayout from "@/layouts/AppLayout.vue";
import DashboardView from "@/views/DashboardView.vue";
import LoginView from "@/views/LoginView.vue";
import PlaceholderView from "@/views/PlaceholderView.vue";
import TwelveMonthInventoryTrendView from "@/views/TwelveMonthInventoryTrendView.vue";

const implementedPages: Record<string, RouteRecordRaw["component"]> = {
    "PG-010": DashboardView,
    "PG-730": TwelveMonthInventoryTrendView,
};

const topLevelRoutes: RouteRecordRaw[] = topLevelPages.map((page) => ({
    path: page.path.slice(1),
    name: page.id,
    component: implementedPages[page.id] ?? PlaceholderView,
    meta: { title: page.label },
}));

const groupRoutes: RouteRecordRaw[] = navigation.flatMap((group) =>
    group.pages.map((page) => ({
        path: page.path.slice(1),
        name: page.id,
        component: implementedPages[page.id] ?? PlaceholderView,
        meta: { title: page.label, group: group.label },
    })),
);

const pageRoutes: RouteRecordRaw[] = [...topLevelRoutes, ...groupRoutes];

const routes: RouteRecordRaw[] = [
    {
        path: "/login",
        name: "login",
        component: LoginView,
        meta: { public: true },
    },
    {
        path: "/",
        component: AppLayout,
        redirect: "/dashboard",
        children: pageRoutes,
    },
    { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { top: 0 };
    },
});

router.beforeEach((to) => {
    const auth = useAuthStore();

    if (!to.meta.public && !auth.isAuthenticated) {
        return { name: "login", query: { redirect: to.fullPath } };
    }

    if (to.name === "login" && auth.isAuthenticated) {
        return { path: "/dashboard" };
    }

    return true;
});

export default router;
