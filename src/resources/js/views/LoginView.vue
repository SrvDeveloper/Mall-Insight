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
 * 見た目はデザイン案A「墨」（K-047）。社内で使うシステムのため、説明の面は置かず、ロゴと入力欄だけを画面の中央に置く。
 */

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const email = ref("");
const password = ref("");
const isSubmitting = ref(false);
const error = ref<string | null>(null);
const showPassword = ref(false);

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

const inputClass =
    "h-11 w-full rounded-lg border border-stone-300 bg-white pl-10 text-sm text-stone-900 placeholder:text-stone-400 focus:border-stone-900 focus:ring-1 focus:ring-stone-900 focus:outline-none";
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-5 py-12">
        <main class="w-full max-w-sm">
            <div class="flex flex-col gap-6">
                <div class="flex items-center gap-3">
                    <AppLogo :size="36" />
                    <div class="flex flex-col leading-tight">
                        <span class="text-base font-bold tracking-tight text-stone-900">Mall Insight</span>
                        <span class="text-[11px] text-stone-500">ECモール統合分析</span>
                    </div>
                </div>

                <div class="flex flex-col gap-6 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-1.5">
                        <h1 class="text-[22px] leading-tight font-bold tracking-tight text-stone-900">ログイン</h1>
                        <p class="text-[13px] text-stone-600">登録されたメールアドレスとパスワードを入力してください。</p>
                    </div>

                    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
                        <p
                            v-if="auth.isExpired && !error"
                            class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-900"
                            data-testid="login-expired"
                        >
                            <svg class="mt-0.5 size-4 shrink-0 text-amber-600" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                                <circle cx="8" cy="8" r="5.5" />
                                <path d="M8 5v3l2 1.5" />
                            </svg>
                            ログインの有効期限が切れました。もう一度ログインしてください。
                        </p>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-xs font-medium text-stone-700">メールアドレス</span>
                            <span class="relative">
                                <svg
                                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-stone-400"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <rect x="2" y="3.5" width="12" height="9" rx="1.5" />
                                    <path d="m2.5 4.5 5.5 4 5.5-4" />
                                </svg>
                                <input v-model="email" type="email" autocomplete="username" placeholder="you@example.com" required :class="inputClass" data-testid="login-email" />
                            </span>
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-xs font-medium text-stone-700">パスワード</span>
                            <span class="relative">
                                <svg
                                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-stone-400"
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    aria-hidden="true"
                                >
                                    <rect x="3" y="7" width="10" height="7" rx="1.5" />
                                    <path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2" />
                                </svg>
                                <input
                                    v-model="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="current-password"
                                    required
                                    :class="inputClass"
                                    class="pr-11"
                                    data-testid="login-password"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-1.5 flex size-8 -translate-y-1/2 items-center justify-center rounded-md text-stone-400 hover:bg-stone-100 hover:text-stone-700"
                                    :aria-label="showPassword ? 'パスワードを隠す' : 'パスワードを表示'"
                                    :aria-pressed="showPassword"
                                    data-testid="toggle-password"
                                    @click="showPassword = !showPassword"
                                >
                                    <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8Z" />
                                        <circle cx="8" cy="8" r="2" />
                                        <path v-if="showPassword" d="m2.5 2.5 11 11" />
                                    </svg>
                                </button>
                            </span>
                        </label>

                        <p v-if="error" role="alert" class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-[13px] text-red-700" data-testid="login-error">
                            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                                <circle cx="8" cy="8" r="5.5" />
                                <path d="M8 5v3.5M8 11h.01" />
                            </svg>
                            <span>{{ error }}</span>
                        </p>

                        <button
                            type="submit"
                            class="mt-2 inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-stone-900 text-sm font-semibold text-white hover:bg-stone-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="isSubmitting || email.trim() === '' || password === ''"
                            data-testid="login-submit"
                        >
                            <svg v-if="isSubmitting" class="size-4 animate-spin" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                            </svg>
                            {{ isSubmitting ? "ログイン中…" : "ログイン" }}
                        </button>
                    </form>
                </div>

                <p class="text-center text-[11px] text-stone-500">利用者の登録・パスワードの再設定は、管理者に依頼してください。</p>
            </div>
        </main>
    </div>
</template>
