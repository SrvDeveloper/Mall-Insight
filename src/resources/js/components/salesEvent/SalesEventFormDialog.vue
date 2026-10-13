<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from "vue";
import SalesEventForm from "@/components/salesEvent/SalesEventForm.vue";
import type { SalesEvent, SalesEventWithPeriods } from "@/types/api";

/**
 * イベントの登録・変更のモーダル（B-129）。イベントの一覧（登録）と、イベントの画面（変更）で使う。
 * 保存してから画面に反映されるまで閉じない（Escape や「閉じる」も効かない）。
 */

defineProps<{ event: SalesEvent | null; maxDays: number; defaultMalls?: string[]; refreshAfterSave: (event: SalesEventWithPeriods) => Promise<void> }>();
const emit = defineEmits<{ close: [] }>();

const isSaving = ref(false);
const closeButton = useTemplateRef<HTMLButtonElement>("closeButton");

function close(): void {
    if (!isSaving.value) {
        emit("close");
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        close();
    }
}

onMounted(async () => {
    document.addEventListener("keydown", onKeydown);
    await nextTick();
    closeButton.value?.focus();
});
onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));

function onSaved(): void {
    isSaving.value = false;
    emit("close");
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-stone-900/40 p-4 sm:p-10" @click.self="close">
        <div role="dialog" aria-modal="true" aria-labelledby="event-form-title" class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-black/5">
            <header class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div class="flex flex-col gap-0.5">
                    <h2 id="event-form-title" class="text-base font-bold text-stone-900">{{ event ? "イベントを編集" : "イベントを登録" }}</h2>
                    <p class="text-xs text-stone-500">
                        {{ event ? "期間は、イベントの画面の期間の一覧で追加・変更します。" : `期間は${maxDays}日までです。あとから期間をいくつでも追加できます。` }}
                    </p>
                </div>
                <button
                    ref="closeButton"
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 hover:text-stone-900"
                    aria-label="閉じる"
                    :disabled="isSaving"
                    @click="close"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="m5 5 10 10M15 5 5 15" />
                    </svg>
                </button>
            </header>
            <div class="overflow-y-auto px-5 py-5">
                <SalesEventForm :event="event" :max-days="maxDays" :default-malls="defaultMalls" :refresh-after-save="refreshAfterSave" @saved="onSaved" @saving="isSaving = $event" @cancel="close" />
            </div>
        </div>
    </div>
</template>
