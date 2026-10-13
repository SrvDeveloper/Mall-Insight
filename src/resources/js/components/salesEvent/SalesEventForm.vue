<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { ApiError } from "@/api/client";
import { createSalesEvent, updateSalesEvent } from "@/api/salesEvents";
import { CHANNEL_LABELS, MALLS, daysBetween } from "@/components/salesEvent/eventFormat";
import type { SalesEvent, SalesEventWithPeriods } from "@/types/api";

/**
 * イベントの登録・変更（B-129、K-112・K-114）。名前・モール・メモを入れる。期間はイベントに別に登録するが、
 * 登録するときは最初の期間（初日・最終日・その回のメモ）を一緒に入れられる（空欄なら期間無しで登録する）。
 * 倍率の候補は同じイベントの過去の期間から求めるため、同じ名前のイベントは登録できない。
 */

const props = defineProps<{ event: SalesEvent | null; maxDays: number }>();
const emit = defineEmits<{ saved: [event: SalesEventWithPeriods]; cancel: [] }>();

const isEdit = computed(() => props.event !== null);
const form = reactive({
    name: props.event?.name ?? "",
    malls: (props.event?.malls.map((mall) => mall.value) ?? []) as string[],
    note: props.event?.note ?? "",
    startsOn: "",
    endsOn: "",
    periodNote: "",
});
const isSaving = ref(false);
const errors = ref<Record<string, string[]>>({});

const days = computed(() => daysBetween(form.startsOn, form.endsOn));
const channels = computed(() => [...new Set(MALLS.filter((mall) => form.malls.includes(mall.value)).map((mall) => CHANNEL_LABELS[mall.channel]))]);

function errorOf(...keys: string[]): string | null {
    for (const key of keys) {
        const message = errors.value[key]?.[0];
        if (message) {
            return message;
        }
    }
    return null;
}

async function save(): Promise<void> {
    if (isSaving.value) {
        return;
    }
    isSaving.value = true;
    errors.value = {};
    const payload = {
        name: form.name.trim(),
        malls: MALLS.map((mall) => mall.value).filter((mall) => form.malls.includes(mall)),
        note: form.note.trim() || null,
        period: !isEdit.value && (form.startsOn || form.endsOn) ? { starts_on: form.startsOn, ends_on: form.endsOn, note: form.periodNote.trim() || null } : null,
    };
    try {
        const saved = props.event ? await updateSalesEvent(props.event.id, payload) : await createSalesEvent(payload);
        emit("saved", saved.data);
    } catch (error) {
        if (error instanceof ApiError && error.isValidationError) {
            errors.value = error.errors;
        }
    } finally {
        isSaving.value = false;
    }
}

const inputClass = "h-10 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
const labelClass = "text-xs font-medium text-stone-700";
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="save">
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">名前</span>
            <input v-model="form.name" type="text" maxlength="100" placeholder="例：楽天スーパーセール" :class="inputClass" data-testid="event-name" />
            <span v-if="errorOf('name')" class="text-xs text-red-700" data-testid="name-error">{{ errorOf("name") }}</span>
        </label>

        <fieldset class="flex flex-col gap-1.5">
            <legend :class="labelClass" class="mb-1.5">
                モール<span class="ml-1 font-normal text-stone-500">（イベントの日を、そのモールのチャネルの平均日販から除きます。すべての期間で同じです）</span>
            </legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="mall in MALLS"
                    :key="mall.value"
                    class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg border px-3 text-[13px]"
                    :class="form.malls.includes(mall.value) ? 'border-stone-900 bg-stone-50 font-semibold text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-300'"
                >
                    <input v-model="form.malls" type="checkbox" :value="mall.value" class="size-4 accent-stone-900" :data-testid="`event-mall-${mall.value}`" />
                    {{ mall.label }}
                </label>
            </div>
            <span v-if="channels.length > 0" class="text-[11px] text-stone-500" data-testid="event-channels"
                >チャネル：{{ channels.join("・") }}（楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS）</span
            >
            <span v-if="errorOf('malls', 'malls.0')" class="text-xs text-red-700">{{ errorOf("malls", "malls.0") }}</span>
        </fieldset>

        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">メモ<span class="ml-1 font-normal text-stone-500">（任意）</span></span>
            <input v-model="form.note" type="text" maxlength="500" placeholder="例：3・6・9・12月の4日20時から" :class="inputClass" />
            <span v-if="errorOf('note')" class="text-xs text-red-700">{{ errorOf("note") }}</span>
        </label>

        <fieldset v-if="!isEdit" class="flex flex-col gap-3 rounded-lg border border-stone-200 px-4 py-3">
            <legend class="px-1 text-xs font-medium text-stone-700">最初の期間<span class="ml-1 font-normal text-stone-500">（任意。あとからいくつでも追加できます）</span></legend>
            <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
                <label class="flex flex-col gap-1.5">
                    <span :class="labelClass">初日</span>
                    <input v-model="form.startsOn" type="date" :class="inputClass" data-testid="event-starts-on" />
                    <span v-if="errorOf('period.starts_on')" class="text-xs text-red-700">{{ errorOf("period.starts_on") }}</span>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="labelClass">最終日<span class="ml-1 font-normal text-stone-500">（この日を含む）</span></span>
                    <input v-model="form.endsOn" type="date" :min="form.startsOn || undefined" :class="inputClass" data-testid="event-ends-on" />
                    <span v-if="errorOf('period.ends_on')" class="text-xs text-red-700" data-testid="ends-on-error">{{ errorOf("period.ends_on") }}</span>
                </label>
                <p class="flex h-10 items-center text-[13px] text-stone-600 tabular-nums sm:mt-[22px]" data-testid="event-days">
                    <template v-if="days !== null && days > 0">
                        <span :class="days > maxDays ? 'font-semibold text-red-700' : ''">{{ days }}日間</span>
                    </template>
                    <template v-else>—</template>
                </p>
            </div>
            <label class="flex flex-col gap-1.5">
                <span :class="labelClass">その回のメモ<span class="ml-1 font-normal text-stone-500">（任意）</span></span>
                <input v-model="form.periodNote" type="text" maxlength="500" placeholder="例：ポイント10倍" :class="inputClass" />
            </label>
        </fieldset>

        <div class="flex justify-end gap-2 border-t border-stone-200 pt-4">
            <button type="button" class="h-10 rounded-lg border border-stone-300 bg-white px-4 text-[13px] font-medium text-stone-700 hover:bg-stone-100" @click="emit('cancel')">キャンセル</button>
            <button
                type="submit"
                class="h-10 rounded-lg bg-stone-900 px-5 text-[13px] font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="isSaving"
                data-testid="event-save"
            >
                {{ isSaving ? "保存中…" : "保存" }}
            </button>
        </div>
    </form>
</template>
