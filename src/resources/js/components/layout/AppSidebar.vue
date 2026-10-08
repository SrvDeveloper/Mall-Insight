<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { navigation } from "@/data/navigation";
import AppLogo from "@/components/layout/AppLogo.vue";
import { useAuthStore } from "@/stores/auth";

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

const auth = useAuthStore();
const router = useRouter();
const isLoggingOut = ref(false);

async function logout(): Promise<void> {
    isLoggingOut.value = true;
    try {
        await auth.logout();
        await router.replace({ name: "login" });
    } finally {
        isLoggingOut.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-30 bg-stone-900/30 lg:hidden" aria-hidden="true" @click="emit('close')" />

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-stone-200 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <router-link to="/" class="flex items-center gap-2.5 px-6 py-6 text-stone-900" @click="emit('close')">
            <AppLogo :size="28" />
            <span class="flex flex-col leading-tight">
                <span class="text-[15px] font-bold tracking-tight">Mall Insight</span>
                <span class="text-[11px] text-stone-500">ECモール統合分析</span>
            </span>
        </router-link>

        <nav class="flex flex-1 flex-col gap-6 overflow-y-auto px-4 pb-6" aria-label="メインメニュー">
            <div v-for="group in navigation" :key="group.label" class="flex flex-col gap-1">
                <p class="px-2.5 pb-1 text-[11px] font-semibold tracking-widest text-stone-400">{{ group.label }}</p>
                <router-link
                    v-for="page in group.pages"
                    :key="page.name"
                    :to="page.path"
                    class="group/nav flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                    active-class="bg-stone-900 font-semibold text-white hover:bg-stone-900 hover:text-white"
                    @click="emit('close')"
                >
                    <span class="size-1.5 rounded-full bg-stone-300 group-aria-[current=page]/nav:bg-white" aria-hidden="true" />
                    {{ page.label }}
                </router-link>
            </div>
        </nav>

        <div v-if="auth.user" class="flex items-center gap-2.5 border-t border-stone-200 px-4 py-3.5" data-testid="current-user">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-stone-100 text-xs font-semibold text-stone-700" aria-hidden="true">{{ auth.user.name.slice(0, 1) }}</span>
            <span class="flex min-w-0 flex-1 flex-col leading-tight">
                <span class="truncate text-[13px] font-medium text-stone-900">{{ auth.user.name }}</span>
                <span class="truncate text-[11px] text-stone-500">{{ auth.user.email }}</span>
            </span>
            <button
                type="button"
                class="shrink-0 rounded-md px-2 py-1 text-xs text-stone-600 hover:bg-stone-100 hover:text-stone-900 disabled:cursor-wait disabled:opacity-60"
                :disabled="isLoggingOut"
                data-testid="logout"
                @click="logout"
            >
                ログアウト
            </button>
        </div>
    </aside>
</template>
