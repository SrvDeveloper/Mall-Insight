<script setup lang="ts">
import { computed, ref } from "vue";
import { arcPath, type DonutSegment } from "@/components/actuals/rankingDonut";

/**
 * 売上上位の円グラフ（ドーナツ、K-097）。上位の品番・その他の品番・未登録のSKUの割合を、12時の位置から時計回りに順位の順（まとめた区分は最後）で描く。
 * 区分の間は2pxの白い隙間で分ける。区分にマウスを乗せる（フォーカスする）と、中央にその区分の名前・値・割合を出し、乗せていないときは合計を出す。
 * 凡例は右に置き、名前と割合を並べる（色だけで見分けさせない）。
 */

const props = defineProps<{ segments: DonutSegment[]; total: number; format: (value: number) => string; totalLabel: string }>();

const hovered = ref<string | null>(null);

const arcs = computed(() => {
    let start = 0;
    return props.segments.map((segment) => {
        const end = start + segment.share;
        const arc = { segment, path: arcPath(start, Math.min(1, end)) };
        start = end;
        return arc;
    });
});

const focused = computed(() => props.segments.find((segment) => segment.key === hovered.value) ?? null);
const formatShare = (share: number): string => `${(share * 100).toFixed(1)}%`;
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-3" data-testid="ranking-donut">
        <div class="relative size-44 shrink-0">
            <svg viewBox="0 0 200 200" class="size-full" role="img" :aria-label="`${totalLabel}の品番ごとの割合`" @mouseleave="hovered = null">
                <path
                    v-for="arc in arcs"
                    :key="arc.segment.key"
                    :d="arc.path"
                    :fill="arc.segment.color"
                    fill-rule="evenodd"
                    class="cursor-default stroke-white transition-opacity focus:outline-none"
                    :class="hovered !== null && hovered !== arc.segment.key ? 'opacity-40' : ''"
                    stroke-width="2"
                    stroke-linejoin="round"
                    tabindex="0"
                    :aria-label="`${arc.segment.label} ${format(arc.segment.value)}（${formatShare(arc.segment.share)}）`"
                    data-testid="donut-segment"
                    @mouseenter="hovered = arc.segment.key"
                    @focus="hovered = arc.segment.key"
                    @blur="hovered = null"
                />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-9 text-center" data-testid="donut-center">
                <template v-if="focused">
                    <span class="w-full truncate text-[11px] text-stone-500" :class="focused.isGroup ? '' : 'font-mono'">{{ focused.label }}</span>
                    <span class="text-base font-semibold text-stone-900 tabular-nums">{{ formatShare(focused.share) }}</span>
                    <span class="text-[11px] text-stone-500 tabular-nums">{{ format(focused.value) }}</span>
                </template>
                <template v-else>
                    <span class="text-[11px] text-stone-500">{{ totalLabel }}</span>
                    <span class="text-sm font-semibold text-stone-900 tabular-nums">{{ format(total) }}</span>
                </template>
            </div>
        </div>
        <ul class="flex min-w-48 flex-col gap-1.5 text-xs" aria-label="円グラフの凡例" data-testid="donut-legend">
            <li
                v-for="segment in segments"
                :key="segment.key"
                class="flex items-center gap-2 rounded px-1 py-0.5"
                :class="hovered === segment.key ? 'bg-stone-100' : ''"
                @mouseenter="hovered = segment.key"
                @mouseleave="hovered = null"
            >
                <span class="size-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: segment.color }" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate text-stone-700" :class="segment.isGroup ? '' : 'font-mono'">{{ segment.label }}</span>
                <span class="text-stone-900 tabular-nums">{{ formatShare(segment.share) }}</span>
            </li>
        </ul>
    </div>
</template>
