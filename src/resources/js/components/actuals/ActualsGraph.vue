<script setup lang="ts">
import { computed } from "vue";
import { actualSparkline, type ActualColumn, type ActualItemRows } from "@/components/actuals/actualRows";

/**
 * 実績（B-124）のグラフ。在庫推移のグラフ（K-047）とそろえ、品番ごとのカードにSKUの推移を小さなグラフで並べる。
 * データの無い列では線を切り、在庫0の点は赤く示す。グラフは画面の幅に合わせて自動で折り返す。
 */

const props = defineProps<{ items: ActualItemRows[]; columns: ActualColumn[]; kind: "sales" | "stock"; summaryLabel: string; notSoldLabel: string }>();

const tiles = computed(() =>
    props.items.map((group) => ({
        group,
        rows: group.rows.map((row) => ({ row, line: row.sku.not_sold ? null : actualSparkline(row.values, props.kind) })),
    })),
);
const axis = computed(() => ({ first: props.columns[0]?.label ?? "", last: props.columns.at(-1)?.label ?? "" }));
</script>

<template>
    <div class="flex flex-col gap-4 p-4" data-testid="actuals-graph">
        <section v-for="tile in tiles" :key="tile.group.item.item_no" class="flex flex-col gap-3 rounded-xl border border-stone-200 bg-white p-4" data-testid="graph-item">
            <div class="flex flex-wrap items-center gap-2.5">
                <h3 class="font-mono text-base font-medium text-stone-900">{{ tile.group.item.item_no }}</h3>
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600">{{ tile.group.item.brand }}・{{ tile.group.item.category }}</span>
                <span class="text-xs text-stone-500"
                    >{{ summaryLabel }} <strong class="text-stone-900 tabular-nums">{{ tile.group.summaryText }}</strong></span
                >
            </div>
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(min(100%, 14.5rem), 1fr))">
                <div v-for="entry in tile.rows" :key="entry.row.sku.sku_id" class="flex flex-col gap-2 rounded-lg border border-stone-200 bg-white px-3 pt-3 pb-2.5" data-testid="graph-tile">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-mono text-[12.5px] text-stone-900" :title="entry.row.sku.sku_code">{{ entry.row.sku.sku_code }}</span>
                        <span class="shrink-0 rounded-full bg-stone-100 px-2 py-px text-[11px] font-semibold text-stone-700 tabular-nums">{{ summaryLabel }} {{ entry.row.summaryText }}</span>
                    </div>
                    <template v-if="entry.line">
                        <svg viewBox="0 0 240 56" preserveAspectRatio="none" class="block h-14 w-full" role="img" :aria-label="`${entry.row.sku.sku_code} の推移`">
                            <line x1="0" x2="240" y1="51" y2="51" class="stroke-stone-300" stroke-width="1" />
                            <path v-for="(segment, index) in entry.line.segments" :key="index" :d="segment" fill="none" class="stroke-stone-900" stroke-width="1.6" stroke-linejoin="round" />
                            <circle
                                v-for="(point, index) in entry.line.points"
                                :key="`p${index}`"
                                :cx="point.x"
                                :cy="point.y"
                                :r="point.isEmpty ? 2.6 : 1.6"
                                :class="point.isEmpty ? 'fill-red-600' : 'fill-stone-900'"
                            />
                        </svg>
                        <div class="flex justify-between text-[10px] text-stone-400">
                            <span>{{ axis.first }}</span>
                            <span>{{ axis.last }}</span>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex h-[4.5rem] items-center justify-center rounded-md bg-[repeating-linear-gradient(135deg,var(--color-stone-50)_0_8px,var(--color-stone-100)_8px_16px)] px-3 text-center text-xs text-stone-500"
                    >
                        {{ entry.row.sku.not_sold ? notSoldLabel : "データがありません" }}
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
