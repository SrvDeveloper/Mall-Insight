<script setup lang="ts">
import { storeToRefs } from "pinia";
import { useNotificationStore } from "@/stores/notifications";

const store = useNotificationStore();
const { notifications } = storeToRefs(store);
</script>

<template>
    <div v-if="notifications.length > 0" class="flex flex-col gap-2 px-4 pt-4 lg:px-8">
        <div v-for="notification in notifications" :key="notification.id" role="alert" class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                    clip-rule="evenodd"
                />
            </svg>
            <p class="flex-1">{{ notification.message }}</p>
            <button type="button" class="shrink-0 rounded px-1.5 text-xs font-semibold text-red-700 hover:bg-red-100" @click="store.dismiss(notification.id)">閉じる</button>
        </div>
    </div>
</template>
