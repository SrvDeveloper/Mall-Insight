<script setup lang="ts">
import { MALL_SERIES } from "@/components/actuals/actualRows";

/**
 * BOSS（青）と Amazon（橙）を横に並べた棒（K-081）。boss・amazon は棒の全体の長さに対する割合（0～1）。
 * 長さで多さを、色の配分でモールの割合を示す。track を付けると、残りを薄い灰色で示す。
 */

withDefaults(defineProps<{ boss: number; amazon: number; height?: number; track?: boolean }>(), { height: 6, track: false });

const [BOSS, AMAZON] = MALL_SERIES;
</script>

<template>
    <svg
        width="100%"
        :height="height"
        :viewBox="`0 0 100 ${height}`"
        preserveAspectRatio="none"
        aria-hidden="true"
        class="block"
        data-testid="mall-bar"
        :data-boss="boss.toFixed(3)"
        :data-amazon="amazon.toFixed(3)"
    >
        <rect v-if="track" x="0" y="0" width="100" :height="height" class="fill-stone-100" />
        <rect x="0" y="0" :width="boss * 100" :height="height" :fill="BOSS!.color" />
        <rect :x="boss * 100" y="0" :width="amazon * 100" :height="height" :fill="AMAZON!.color" />
    </svg>
</template>
