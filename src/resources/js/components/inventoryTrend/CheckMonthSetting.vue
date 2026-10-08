<script setup lang="ts">
import { computed, ref } from "vue";
import { ApiError } from "@/api/client";
import { saveInventoryTrendSettings } from "@/api/inventoryTrends";
import { longMonthLabel } from "@/components/inventoryTrend/trendRows";
import type { InventoryTrendSettings } from "@/types/api";

/**
 * 在庫推移の「判定する月」の表示と変更（バックログ B-011、決定記録 K-050）。
 * 変更は全員の在庫推移に効く基準として保存され、保存したら在庫推移を計算し直す（saved を受けた親が読み込み直す）。
 */

const props = defineProps<{ settings: InventoryTrendSettings; checkMonth: string; currentMonth: string }>();
const emit = defineEmits<{ saved: [] }>();

/** 選べる判定する月（今月から何か月後か）。推移は今月から11か月後までの12か月（K-050）。 */
const MIN_OFFSET = 1;
const MAX_OFFSET = 11;

const isEditing = ref(false);
const isSaving = ref(false);
const selected = ref(props.settings.check_month_offset);
const error = ref<string | null>(null);

function addMonths(month: string, count: number): string {
    const date = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1 + count, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

const options = computed(() =>
    Array.from({ length: MAX_OFFSET - MIN_OFFSET + 1 }, (_, index) => {
        const offset = MIN_OFFSET + index;
        return { offset, label: `${offset}か月後（${longMonthLabel(addMonths(props.currentMonth, offset))}）` };
    }),
);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", month: "numeric", day: "numeric", hour: "2-digit", minute: "2-digit" });
const note = computed(() => {
    const offset = `${props.settings.check_month_offset}か月後`;
    return props.settings.changed_at ? `${offset}・${dateTimeFormat.format(new Date(props.settings.changed_at))} に変更` : `${offset}・初期値`;
});

function startEditing(): void {
    selected.value = props.settings.check_month_offset;
    error.value = null;
    isEditing.value = true;
}

async function save(): Promise<void> {
    if (selected.value === props.settings.check_month_offset) {
        isEditing.value = false;
        return;
    }
    isSaving.value = true;
    error.value = null;
    try {
        await saveInventoryTrendSettings(selected.value);
        isEditing.value = false;
        emit("saved");
    } catch (caught) {
        error.value = caught instanceof ApiError && caught.isValidationError ? (Object.values(caught.errors).flat()[0] ?? caught.message) : "保存できませんでした。";
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] tracking-wide text-stone-500">判定する月</span>
            <button
                v-if="!isEditing"
                type="button"
                class="text-[11px] font-medium text-stone-500 underline-offset-2 hover:text-stone-900 hover:underline"
                data-testid="check-month-edit"
                @click="startEditing"
            >
                変更
            </button>
        </div>
        <template v-if="!isEditing">
            <span class="text-base font-semibold text-stone-900 tabular-nums">{{ longMonthLabel(checkMonth) }}</span>
            <span class="text-xs text-stone-400" data-testid="check-month-note">{{ note }}</span>
        </template>
        <form v-else class="flex flex-col gap-1.5" @submit.prevent="save">
            <label class="sr-only" for="check-month-offset">判定する月</label>
            <select
                id="check-month-offset"
                v-model.number="selected"
                class="h-8 rounded-md border border-stone-300 bg-white px-2 text-[13px] text-stone-900 focus:border-stone-900 focus:outline-none"
                :disabled="isSaving"
                data-testid="check-month-select"
            >
                <option v-for="option in options" :key="option.offset" :value="option.offset">{{ option.label }}</option>
            </select>
            <div class="flex items-center gap-1.5">
                <button
                    type="submit"
                    class="h-7 rounded-md bg-stone-900 px-3 text-xs font-semibold text-white hover:bg-stone-700 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isSaving"
                    data-testid="check-month-save"
                >
                    {{ isSaving ? "保存中…" : "保存して再計算" }}
                </button>
                <button type="button" class="h-7 rounded-md px-2 text-xs text-stone-600 hover:bg-stone-100" :disabled="isSaving" @click="isEditing = false">取消</button>
            </div>
            <span v-if="error" class="text-xs font-medium text-red-700" data-testid="check-month-error">{{ error }}</span>
            <span v-else class="text-[11px] text-stone-400">全員の在庫推移に使われます</span>
        </form>
    </div>
</template>
