<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import AppLogo from "@/components/layout/AppLogo.vue";
import { afterLoginLocation } from "@/router/afterLogin";
import { useAuthStore } from "@/stores/auth";

/**
 * ログイン（バックログ B-009、決定記録 K-051）。登録した利用者だけが、メールアドレスとパスワードでログインできる。
 * ログインしたら、開こうとしていた画面に戻る。
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const email = ref("");
const password = ref("");
const isSubmitting = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    isSubmitting.value = true;
    error.value = null;
    try {
        await auth.login(email.value, password.value);
        await router.replace(afterLoginLocation(route));
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.isValidationError
                ? (Object.values(caught.errors).flat()[0] ?? caught.message)
                : caught instanceof ApiError
                  ? caught.message
                  : "ログインできませんでした。";
        password.value = "";
    } finally {
        isSubmitting.value = false;
    }
}

const inputClass = "h-10 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:outline-none";
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="flex w-full max-w-[22rem] flex-col gap-7">
            <div class="flex flex-col items-center gap-3 text-center">
                <AppLogo :size="44" />
                <div class="flex flex-col gap-0.5">
                    <h1 class="text-lg font-bold tracking-tight text-stone-900">Mall Insight</h1>
                    <p class="text-xs text-stone-500">ECモール統合分析</p>
                </div>
            </div>

            <form class="flex flex-col gap-4 rounded-xl border border-stone-200 bg-white p-6" novalidate @submit.prevent="submit">
                <p v-if="auth.isExpired && !error" class="rounded-lg bg-amber-50 px-3 py-2 text-[13px] text-amber-800" data-testid="login-expired">
                    ログインの有効期限が切れました。もう一度ログインしてください。
                </p>
                <label class="flex flex-col gap-1.5">
                    <span class="text-xs font-medium text-stone-700">メールアドレス</span>
                    <input v-model="email" type="email" autocomplete="username" required :class="inputClass" data-testid="login-email" />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-xs font-medium text-stone-700">パスワード</span>
                    <input v-model="password" type="password" autocomplete="current-password" required :class="inputClass" data-testid="login-password" />
                </label>

                <p v-if="error" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-700" data-testid="login-error">{{ error }}</p>

                <button
                    type="submit"
                    class="mt-1 h-10 rounded-lg bg-stone-900 text-sm font-semibold text-white hover:bg-stone-700 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isSubmitting || email.trim() === '' || password === ''"
                    data-testid="login-submit"
                >
                    {{ isSubmitting ? "ログイン中…" : "ログイン" }}
                </button>
            </form>

            <p class="text-center text-[11px] text-stone-400">利用者の登録・パスワードの再設定は、管理者に依頼してください。</p>
        </div>
    </div>
</template>
