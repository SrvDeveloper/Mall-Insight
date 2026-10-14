<script setup lang="ts">
import { computed, ref } from "vue";
import type { CurrentItemSelection, ItemRank, ItemRanking, SelectionScope } from "@/types/api";

/**
 * 対象品番の画面の「全体」タブ（B-136、K-130）。全体の対象品番は Amazon・BOSS の対象品番を合わせたもので、ここでは確定しない。
 * 全体の売上順位の順に、どちらのチャネルの対象品番かを並べる。未確定のチャネルは、そのチャネルの売上上位の品番を使っていることを示す。
 */

const props = defineProps<{ ranking: ItemRanking; current: CurrentItemSelection }>();
const emit = defineEmits<{ select: [scope: SelectionScope] }>();

type Filter = "target" | "all";
const filter = ref<Filter>("target");

const amazon = computed(() => new Set(props.current.meta.amazon_item_nos ?? []));
const boss = computed(() => new Set(props.current.meta.boss_item_nos ?? []));
const isTarget = (rank: ItemRank): boolean => amazon.value.has(rank.item_no) || boss.value.has(rank.item_no);

const rows = computed(() => props.ranking.data.filter((rank) => filter.value === "all" || isTarget(rank)));
const maxSalesAmount = computed(() => Math.max(1, ...props.ranking.data.map((rank) => rank.sales_amount ?? 0)));
const salesPercent = (rank: ItemRank): string => `${((rank.sales_amount ?? 0) / maxSalesAmount.value) * 100}%`;
const formatYen = (value: number): string => `¥${value.toLocaleString()}`;

const channels = computed(() => [
    { scope: "amazon" as const, label: "Amazon", count: amazon.value.size, usesCandidates: props.current.meta.amazon_uses_candidates ?? false },
    { scope: "boss" as const, label: "BOSS", count: boss.value.size, usesCandidates: props.current.meta.boss_uses_candidates ?? false },
]);
</script>

<template>
    <section class="grid grid-cols-1 rounded-xl border border-stone-200 bg-white sm:grid-cols-3" aria-label="全体の対象品番" data-testid="total-overview">
        <div class="flex flex-col gap-1 px-5 py-4">
            <span class="text-[11px] tracking-wide text-stone-500">全体の対象品番</span>
            <span class="text-base font-semibold text-stone-900 tabular-nums" data-testid="total-count">{{ current.meta.item_nos.length }}品番</span>
            <span class="text-xs text-stone-400">Amazon・BOSS の対象品番を合わせたもの</span>
        </div>
        <div v-for="channel in channels" :key="channel.scope" class="flex flex-col gap-1 border-stone-200 px-5 py-4 max-sm:border-t sm:border-l" :data-testid="`total-${channel.scope}`">
            <span class="text-[11px] tracking-wide text-stone-500">{{ channel.label }}の対象品番</span>
            <span class="text-base font-semibold tabular-nums" :class="channel.usesCandidates ? 'text-amber-800' : 'text-stone-900'">{{ channel.count }}品番</span>
            <span class="text-xs" :class="channel.usesCandidates ? 'font-medium text-amber-700' : 'text-stone-400'">
                <template v-if="channel.usesCandidates">未確定のため、{{ channel.label }}の売上上位の品番を使っています</template>
                <template v-else>確定済み</template>
                <button type="button" class="ml-1 font-medium text-stone-700 underline underline-offset-2 hover:text-stone-900" @click="emit('select', channel.scope)">
                    {{ channel.usesCandidates ? "確定する" : "見直す" }}
                </button>
            </span>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-stone-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
            <div class="inline-flex flex-wrap gap-1.5" role="group" aria-label="絞り込み">
                <button
                    v-for="option in [
                        { value: 'target' as Filter, label: '全体の対象品番', count: current.meta.item_nos.length },
                        { value: 'all' as Filter, label: 'すべて', count: ranking.data.length },
                    ]"
                    :key="option.value"
                    type="button"
                    class="h-8 rounded-full border bg-white px-3 text-[13px]"
                    :class="filter === option.value ? 'border-stone-900 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                    :aria-pressed="filter === option.value"
                    :data-testid="`total-filter-${option.value}`"
                    @click="filter = option.value"
                >
                    {{ option.label }} <span class="text-stone-500 tabular-nums">{{ option.count }}</span>
                </button>
            </div>
            <p class="text-xs text-stone-500">全体の対象品番は、Amazon・BOSS のタブで確定した品番を合わせたものです。変えるときは Amazon・BOSS のタブで確定してください。</p>
        </div>
        <div v-if="rows.length === 0" class="px-6 py-16 text-center text-sm text-stone-500">対象品番がありません。</div>
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[48rem] border-separate border-spacing-0 text-left text-[13px]">
                <thead class="text-xs font-medium text-stone-500 [&_th]:border-b [&_th]:border-stone-300 [&_th]:bg-white">
                    <tr>
                        <th scope="col" class="w-20 px-4 py-2.5 text-right">全体の順位</th>
                        <th scope="col" class="px-3 py-2.5">品番</th>
                        <th scope="col" class="w-72 px-3 py-2.5">売上金額（全体）</th>
                        <th scope="col" class="w-24 px-3 py-2.5 text-center">Amazon</th>
                        <th scope="col" class="w-24 px-4 py-2.5 text-center">BOSS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rank in rows" :key="rank.item_id" class="h-11 [&>*]:border-t [&>*]:border-stone-100" :class="isTarget(rank) ? '' : 'text-stone-400'" data-testid="total-row">
                        <td class="px-4 text-right tabular-nums">{{ rank.sales_rank ?? "—" }}</td>
                        <td class="px-3 whitespace-nowrap">
                            <span class="font-mono text-sm font-medium" :class="isTarget(rank) ? 'text-stone-900' : 'text-stone-500'" data-testid="total-item-no">{{ rank.item_no }}</span>
                            <span class="ml-2 rounded-full border border-stone-200 bg-white px-2 py-px text-[11px] text-stone-600">{{ rank.brand }}・{{ rank.category }}</span>
                        </td>
                        <td class="px-3">
                            <div v-if="rank.sales_amount !== null" class="flex items-center gap-2.5">
                                <span class="h-1.5 w-32 shrink-0 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                    <span class="block h-full rounded-full bg-stone-700" :style="{ width: salesPercent(rank) }" />
                                </span>
                                <span class="tabular-nums" :class="isTarget(rank) ? 'text-stone-900' : 'text-stone-500'">{{ formatYen(rank.sales_amount) }}</span>
                            </div>
                            <span v-else class="text-stone-400">実績なし</span>
                        </td>
                        <td class="px-3 text-center" data-testid="total-amazon">
                            <span v-if="amazon.has(rank.item_no)" class="rounded-full bg-stone-900 px-2 py-0.5 text-[11px] font-semibold text-white">対象</span>
                            <span v-else class="text-stone-300">—</span>
                        </td>
                        <td class="px-4 text-center" data-testid="total-boss">
                            <span v-if="boss.has(rank.item_no)" class="rounded-full bg-stone-900 px-2 py-0.5 text-[11px] font-semibold text-white">対象</span>
                            <span v-else class="text-stone-300">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
