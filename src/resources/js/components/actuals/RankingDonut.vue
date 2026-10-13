<script setup lang="ts">
import { computed, ref, useId } from "vue";
import { arcPath, swatchStyle, type DonutSegment } from "@/components/actuals/rankingDonut";

/**
 * 売上上位の円グラフ（ドーナツ、K-097・K-098）。上位10品番・その他の品番・未登録のSKUの割合を、12時の位置から時計回りに順位の順（まとめた区分は最後）で描く。
 * 区分の間は2pxの白い隙間で分け、8～10位は同じ色の濃い斜線を重ねる。区分にマウスを乗せる（フォーカスする）と、中央にその区分の名前・割合・値を出し、
 * 乗せていないときは合計を出す。凡例は円グラフの下に置き、順位・名前・割合を並べる（色だけで見分けさせない）。
 */

const props = defineProps<{ segments: DonutSegment[]; total: number; format: (value: number) => string; totalLabel: string }>();

const hovered = ref<string | null>(null);
const patternPrefix = useId();
const patternId = (segment: DonutSegment): string => `${patternPrefix}-${segment.key}`;

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
    <div class="flex flex-col items-center gap-4" data-testid="ranking-donut">
        <div class="relative size-52 shrink-0">
            <svg viewBox="0 0 200 200" class="size-full" role="img" :aria-label="`${totalLabel}の品番ごとの割合`" @mouseleave="hovered = null">
                <defs>
                    <pattern
                        v-for="segment in segments.filter((item) => item.ink)"
                        :id="patternId(segment)"
                        :key="segment.key"
                        width="6"
                        height="6"
                        patternUnits="userSpaceOnUse"
                        patternTransform="rotate(45)"
                    >
                        <rect width="6" height="6" :fill="segment.color" />
                        <line x1="0" y1="0" x2="0" y2="6" :stroke="segment.ink!" stroke-width="2.4" />
                    </pattern>
                </defs>
                <path
                    v-for="arc in arcs"
                    :key="arc.segment.key"
                    :d="arc.path"
                    :fill="arc.segment.ink ? `url(#${patternId(arc.segment)})` : arc.segment.color"
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
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-12 text-center" data-testid="donut-center">
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
        <ul class="grid w-full grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2" aria-label="円グラフの凡例" data-testid="donut-legend">
            <li
                v-for="(segment, index) in segments"
                :key="segment.key"
                class="flex min-w-0 items-center gap-2 rounded px-1 py-0.5"
                :class="hovered === segment.key ? 'bg-stone-100' : ''"
                @mouseenter="hovered = segment.key"
                @mouseleave="hovered = null"
            >
                <span class="size-2.5 shrink-0 rounded-sm" :style="swatchStyle(segment)" aria-hidden="true" data-testid="legend-swatch" />
                <span class="w-4 shrink-0 text-right text-[10px] text-stone-400 tabular-nums">{{ segment.isGroup ? "" : index + 1 }}</span>
                <span class="min-w-0 flex-1 truncate text-stone-700" :class="segment.isGroup ? '' : 'font-mono'">{{ segment.label }}</span>
                <span class="text-stone-900 tabular-nums">{{ formatShare(segment.share) }}</span>
            </li>
        </ul>
    </div>
</template>
