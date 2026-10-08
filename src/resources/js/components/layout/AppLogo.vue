<script setup lang="ts">
import { useId } from "vue";

withDefaults(defineProps<{ size?: number }>(), { size: 28 });

// グラデーションの id をインスタンスごとに分ける（ヘッダーとメニューに同時に表示されるため）
const uid = useId();
const ids = { bg: `logo-bg-${uid}`, glow: `logo-glow-${uid}` };

/**
 * 格子のマスの位置と濃さ。マスは各モールの店舗・SKUを表す。
 * 左下から右上へ段々に明るくし、線が奥（過去）から手前の明るいところ（予測）へ伸びる流れを出す（K-016）。
 */
const cells = [
    { x: 12, y: 12, opacity: 0.32 },
    { x: 35, y: 12, opacity: 0.5 },
    { x: 12, y: 35, opacity: 0.18 },
    { x: 35, y: 35, opacity: 0.32 },
];
</script>

<template>
    <!--
        モールの格子と予測線：格子（各モールの店舗・SKU）の上を実績の線が通り、右上の光る点（次の予測）へ向かう。
        24〜28px で潰れないよう、マスは2×2、線の途中の点は省いている。マスは左下から右上へ段々に明るくして奥行きを出し、
        線の下に背景色のふちどりを敷いてマスと線の境目を保つ。public/favicon.svg と同じ図案。
    -->
    <svg :width="size" :height="size" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
            <linearGradient :id="ids.bg" x1="0" y1="64" x2="64" y2="0" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#0c0a09" />
                <stop offset=".6" stop-color="#1c1917" />
                <stop offset="1" stop-color="#44403c" />
            </linearGradient>
            <radialGradient :id="ids.glow" cx="48" cy="20" r="24" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#fff" stop-opacity=".22" />
                <stop offset="1" stop-color="#fff" stop-opacity="0" />
            </radialGradient>
        </defs>
        <rect width="64" height="64" rx="16" :fill="`url(#${ids.bg})`" />
        <rect width="64" height="64" rx="16" :fill="`url(#${ids.glow})`" />
        <rect v-for="cell in cells" :key="`${cell.x}-${cell.y}`" :x="cell.x" :y="cell.y" width="17" height="17" rx="4" fill="#fff" :fill-opacity="cell.opacity" />
        <path d="M13 46 24 36l9 6 14-21" stroke="#1c1917" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="48" cy="20" r="8.5" fill="#1c1917" />
        <path d="M13 46 24 36l9 6 14-21" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="48" cy="20" r="5.5" fill="#fff" />
    </svg>
</template>
