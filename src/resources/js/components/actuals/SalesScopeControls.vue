<script setup lang="ts">
import type { TrendScope } from "@/types/api";

/**
 * 品番別売上・SKU別売上の見出しの右に置く、モール（全体・Amazon・BOSS）の切り替えと再読み込み。
 * 地（stone-100）の上に置くため、白い面と枠で浮かせる（在庫推移の切り替えとそろえる）。
 */

defineProps<{ scope: TrendScope; isLoading: boolean }>();
const emit = defineEmits<{ select: [scope: TrendScope]; reload: [] }>();

const SCOPES: { value: TrendScope; label: string }[] = [
    { value: "total", label: "全体" },
    { value: "amazon", label: "Amazon" },
    { value: "boss", label: "BOSS" },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex items-center gap-0.5 rounded-[10px] border border-stone-300 bg-white p-[3px] shadow-xs" role="tablist" aria-label="モール">
            <button
                v-for="option in SCOPES"
                :key="option.value"
                type="button"
                role="tab"
                class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                :class="scope === option.value ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'"
                :aria-selected="scope === option.value"
                :data-testid="`scope-${option.value}`"
                @click="emit('select', option.value)"
            >
                {{ option.label }}
            </button>
        </div>
        <button
            type="button"
            class="inline-flex h-10 items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-900 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-60"
            :disabled="isLoading"
            @click="emit('reload')"
        >
            <svg class="size-4" :class="isLoading ? 'animate-spin' : ''" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                <path d="M13.5 2.5v3h-3" />
            </svg>
            {{ isLoading ? "読み込み中…" : "再読み込み" }}
        </button>
    </div>
</template>
