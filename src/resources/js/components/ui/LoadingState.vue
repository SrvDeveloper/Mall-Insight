<script setup lang="ts">
/**
 * 読み込み・計算中の表示。block は表示するものがまだ無いときに枠の中へ大きく出し、toast は表示したまま読み直すときに画面の下に小さく出す。
 * 回る印は、動きを減らす設定の利用者には回さない。読み上げでは message を読む。
 */

withDefaults(defineProps<{ message: string; note?: string | null; variant?: "block" | "toast" }>(), { note: null, variant: "block" });
</script>

<template>
    <div v-if="variant === 'block'" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center" role="status" aria-live="polite" data-testid="loading">
        <svg class="size-8 animate-spin text-stone-900 motion-reduce:animate-none" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <circle cx="16" cy="16" r="13" stroke="currentColor" stroke-opacity="0.12" stroke-width="3" />
            <path d="M16 3a13 13 0 0 1 13 13" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <p class="m-0 text-sm font-medium text-stone-700">{{ message }}</p>
        <p v-if="note" class="m-0 text-xs text-stone-500">{{ note }}</p>
    </div>
    <div
        v-else
        class="fixed bottom-6 left-1/2 z-30 inline-flex -translate-x-1/2 items-center gap-2 rounded-full bg-stone-900 px-4 py-2 text-[13px] font-medium text-white shadow-[0_8px_24px_rgba(28,25,23,0.25)]"
        role="status"
        aria-live="polite"
        data-testid="loading-toast"
    >
        <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 32 32" fill="none" aria-hidden="true">
            <circle cx="16" cy="16" r="13" stroke="currentColor" stroke-opacity="0.25" stroke-width="4" />
            <path d="M16 3a13 13 0 0 1 13 13" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
        </svg>
        {{ message }}
    </div>
</template>
