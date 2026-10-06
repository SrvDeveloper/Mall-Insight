<script setup lang="ts">
import { useId } from "vue";

withDefaults(defineProps<{ size?: number }>(), { size: 28 });

// Unique per instance so multiple logos on one page don't share a gradient definition
const gradientId = `app-logo-gradient-${useId()}`;
</script>

<template>
    <!-- Mall Insight mark: shopping bag (EC mall) with a rising trend line (insight / forecast) -->
    <svg :width="size" :height="size" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Mall Insight">
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#3b82f6" />
                <stop offset="1" stop-color="#1d4ed8" />
            </linearGradient>
        </defs>
        <rect width="32" height="32" rx="8" :fill="`url(#${gradientId})`" />
        <path d="M12.5 11.5V10a3.5 3.5 0 0 1 7 0v1.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" />
        <rect x="8" y="11" width="16" height="14.5" rx="2.5" fill="#fff" />
        <path d="M11 22l3.5-3.5 2.5 2 4-4.5" stroke="#2563eb" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="21" cy="16" r="1.5" fill="#2563eb" />
    </svg>
</template>
