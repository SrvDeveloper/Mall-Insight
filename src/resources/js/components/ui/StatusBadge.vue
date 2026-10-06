<script setup lang="ts">
export type BadgeTone = "positive" | "neutral" | "warning" | "danger";

defineProps<{ tone: BadgeTone; label: string }>();

const toneClasses: Record<BadgeTone, { badge: string; dot: string }> = {
    positive: { badge: "bg-emerald-50 text-emerald-700 ring-emerald-600/20", dot: "bg-emerald-500" },
    neutral: { badge: "bg-stone-100 text-stone-600 ring-stone-500/20", dot: "bg-stone-400" },
    warning: { badge: "bg-amber-50 text-amber-800 ring-amber-600/20", dot: "bg-amber-500" },
    danger: { badge: "bg-red-50 text-red-700 ring-red-600/20", dot: "bg-red-500" },
};
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset" :class="toneClasses[tone].badge">
        <span class="size-1.5 rounded-full" :class="toneClasses[tone].dot" aria-hidden="true" />
        {{ label }}
    </span>
</template>
