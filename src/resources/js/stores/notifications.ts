import { ref } from "vue";
import { defineStore } from "pinia";

export interface Notification {
    id: number;
    tone: "error";
    message: string;
}

/**
 * 画面上部に出す全体通知。同じ文言は重ねて表示しない。
 */
export const useNotificationStore = defineStore("notifications", () => {
    const notifications = ref<Notification[]>([]);
    let nextId = 1;

    function notifyError(message: string): void {
        if (notifications.value.some((notification) => notification.message === message)) {
            return;
        }
        notifications.value.push({ id: nextId++, tone: "error", message });
    }

    function dismiss(id: number): void {
        notifications.value = notifications.value.filter((notification) => notification.id !== id);
    }

    return { notifications, notifyError, dismiss };
});
