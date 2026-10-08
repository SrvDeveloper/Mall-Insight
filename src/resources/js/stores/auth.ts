import { ref } from "vue";
import { defineStore } from "pinia";
import * as authApi from "@/api/auth";
import type { AuthUser } from "@/types/api";

/**
 * ログインの状態（B-009）。ログインはサーバーのセッションで管理し、ここには画面を開いたときに問い合わせた結果だけを持つ
 * （ブラウザには保存しない）。
 */
export const useAuthStore = defineStore("auth", () => {
    const user = ref<AuthUser | null>(null);
    const isLoaded = ref(false);
    /** ログインしていたのに、ログインの有効期限が切れた */
    const isExpired = ref(false);
    let loading: Promise<void> | null = null;

    /** 最初の1回だけ、ログインしているかをサーバーに問い合わせる。 */
    async function ensureLoaded(): Promise<void> {
        if (isLoaded.value) {
            return;
        }
        loading ??= authApi
            .fetchCurrentUser()
            .then((current) => {
                user.value = current;
            })
            .catch(() => {
                user.value = null;
            })
            .finally(() => {
                isLoaded.value = true;
                loading = null;
            });
        await loading;
    }

    async function login(email: string, password: string): Promise<void> {
        user.value = await authApi.login(email, password);
        isLoaded.value = true;
        isExpired.value = false;
    }

    async function logout(): Promise<void> {
        await authApi.logout();
        user.value = null;
    }

    /** API が 401 を返したとき。ログインしていた場合だけ有効期限切れとし、true を返す。 */
    function expire(): boolean {
        if (!user.value) {
            return false;
        }
        user.value = null;
        isExpired.value = true;
        return true;
    }

    return { user, isLoaded, isExpired, ensureLoaded, login, logout, expire };
});
