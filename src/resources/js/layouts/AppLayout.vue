<script setup lang="ts">
import { ref } from "vue";
import AppSidebar from "@/components/layout/AppSidebar.vue";
import AppLogo from "@/components/layout/AppLogo.vue";
import NotificationBanner from "@/components/layout/NotificationBanner.vue";

const sidebarOpen = ref(false);
</script>

<template>
    <div class="flex min-h-screen">
        <AppSidebar :open="sidebarOpen" @close="sidebarOpen = false" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-stone-200 bg-white px-4 lg:hidden">
                <button type="button" class="-ml-1 rounded-md p-1.5 text-stone-600 hover:bg-stone-100" aria-label="メニューを開く" @click="sidebarOpen = true">
                    <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                        <path d="M3 5h14M3 10h14M3 15h14" />
                    </svg>
                </button>
                <AppLogo :size="24" />
                <span class="text-sm font-bold tracking-tight">Mall Insight</span>
            </header>

            <NotificationBanner />

            <main class="flex-1 px-4 py-6 lg:px-8 lg:py-8">
                <router-view />
            </main>
        </div>
    </div>
</template>
