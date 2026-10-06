<script setup lang="ts">
import { computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { initFlowbite } from "flowbite";
import { useAuthStore } from "@/stores/auth";
import AppIcon from "@/components/icons/AppIcon.vue";
import AppLogo from "@/components/icons/AppLogo.vue";

defineProps<{ collapsed: boolean }>();
const emit = defineEmits<{ "toggle-sidebar": [] }>();

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const pageTitle = computed(() => (route.meta.title as string | undefined) ?? "");
const groupLabel = computed(() => (route.meta.group as string | undefined) ?? "");

onMounted(() => {
    initFlowbite();
});

function handleLogout(): void {
    auth.logout();
    router.push({ name: "login" });
}
</script>

<template>
    <header class="flex h-15 shrink-0 items-center gap-3.5 border-b border-slate-200 bg-white px-4">
        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100" aria-label="サイドバーの開閉" @click="emit('toggle-sidebar')">
            <AppIcon name="menu" :size="19" class="transition-transform duration-150" :class="collapsed ? 'rotate-180' : ''" />
        </button>

        <div class="h-6 w-px bg-slate-200" />

        <router-link to="/dashboard" class="flex items-center gap-2.5">
            <AppLogo :size="28" />
            <span class="text-[15px] font-bold tracking-tight text-slate-900">Mall <span class="text-primary-600">Insight</span></span>
        </router-link>

        <div class="h-6 w-px bg-slate-200" />

        <div class="flex items-center gap-1.5 text-[13px]">
            <span class="text-slate-400">{{ groupLabel }}</span>
            <span v-if="groupLabel" class="text-slate-300">/</span>
            <span class="font-semibold text-slate-900">{{ pageTitle }}</span>
        </div>

        <div class="flex-1" />

        <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5">
            <span class="text-[11px] text-slate-500">対象年度</span>
            <span class="text-[12.5px] font-semibold text-slate-900">2026年度</span>
        </div>
        <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5">
            <span class="text-[11px] text-slate-500">基準日</span>
            <span class="text-[12.5px] font-semibold text-slate-900">2026-08-31</span>
        </div>

        <div class="h-6 w-px bg-slate-200" />

        <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100" aria-label="通知">
            <AppIcon name="bell" :size="19" />
            <span class="absolute right-1 top-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-red-600 px-0.5 text-[9px] font-bold leading-none text-white">3</span>
        </button>

        <div class="h-6 w-px bg-slate-200" />

        <button id="user-menu-button" type="button" data-dropdown-toggle="user-menu" data-dropdown-placement="bottom-end" class="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-slate-100">
            <span class="flex h-7.5 w-7.5 items-center justify-center rounded-full bg-primary-100 text-[12.5px] font-bold text-primary-700">
                {{ auth.user?.name?.charAt(0) ?? "?" }}
            </span>
            <span class="flex flex-col items-start">
                <span class="text-[12.5px] font-semibold text-slate-900">{{ auth.user?.name ?? "未ログイン" }}</span>
                <span class="text-[10.5px] text-slate-500">{{ auth.user?.role ?? "" }}</span>
            </span>
            <AppIcon name="chevron-down" :size="13" class="text-slate-400" />
        </button>
        <div id="user-menu" class="z-20 hidden w-44 rounded-lg border border-slate-200 bg-white py-1.5 shadow-lg">
            <button type="button" class="flex w-full items-center px-3.5 py-2 text-left text-[13px] text-slate-700 hover:bg-slate-50" @click="handleLogout">ログアウト</button>
        </div>
    </header>
</template>
