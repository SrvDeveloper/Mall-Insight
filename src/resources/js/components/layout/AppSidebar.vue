<script setup lang="ts">
import { navigation } from "@/data/navigation";
import AppLogo from "@/components/layout/AppLogo.vue";

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-30 bg-stone-900/30 lg:hidden" aria-hidden="true" @click="emit('close')" />

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-stone-200 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <router-link to="/" class="flex items-center gap-2.5 px-5 py-5 text-stone-900" @click="emit('close')">
            <AppLogo :size="28" />
            <span class="flex flex-col leading-tight">
                <span class="text-[15px] font-bold tracking-tight">Mall Insight</span>
                <span class="text-[11px] text-stone-500">ECモール統合分析</span>
            </span>
        </router-link>

        <nav class="flex flex-1 flex-col gap-6 overflow-y-auto px-3 pb-6" aria-label="メインメニュー">
            <div v-for="group in navigation" :key="group.label" class="flex flex-col gap-1">
                <p class="px-2 text-[11px] font-semibold tracking-wider text-stone-400">{{ group.label }}</p>
                <router-link
                    v-for="page in group.pages"
                    :key="page.name"
                    :to="page.path"
                    class="rounded-md px-2 py-1.5 text-sm text-stone-600 hover:bg-stone-100 hover:text-stone-900"
                    active-class="bg-stone-900 font-semibold text-white hover:bg-stone-900 hover:text-white"
                    @click="emit('close')"
                >
                    {{ page.label }}
                </router-link>
            </div>
        </nav>
    </aside>
</template>
