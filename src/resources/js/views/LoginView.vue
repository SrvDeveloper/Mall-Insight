<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import BaseInput from "@/components/ui/BaseInput.vue";
import BaseButton from "@/components/ui/BaseButton.vue";
import AppLogo from "@/components/icons/AppLogo.vue";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const email = ref("");
const password = ref("");
const submitting = ref(false);
const errorMessage = ref("");

async function handleSubmit(): Promise<void> {
    if (!email.value || !password.value) {
        errorMessage.value = "メールアドレスとパスワードを入力してください";
        return;
    }

    errorMessage.value = "";
    submitting.value = true;

    // バックエンド未実装のため、入力があればログイン成功として扱うモック処理。
    await new Promise((resolve) => setTimeout(resolve, 450));
    auth.login(email.value);
    submitting.value = false;

    const redirect = typeof route.query.redirect === "string" ? route.query.redirect : "/dashboard";
    router.push(redirect);
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-95">
            <div class="mb-7 flex flex-col items-center gap-3 text-center">
                <AppLogo :size="44" />
                <div>
                    <h1 class="text-lg font-bold tracking-tight text-slate-900">Mall <span class="text-primary-600">Insight</span></h1>
                    <p class="mt-0.5 text-[12.5px] text-slate-500">ECモール統合分析システム</p>
                </div>
            </div>

            <form class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-7 shadow-sm" @submit.prevent="handleSubmit">
                <BaseInput v-model="email" type="email" label="メールアドレス" placeholder="you@example.com" required autocomplete="username" />
                <BaseInput v-model="password" type="password" label="パスワード" placeholder="••••••••" required autocomplete="current-password" />

                <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-[12.5px] text-red-600">{{ errorMessage }}</p>

                <BaseButton type="submit" :disabled="submitting" class="w-full justify-center">
                    {{ submitting ? "ログイン中…" : "ログイン" }}
                </BaseButton>
            </form>

            <p class="mt-5 text-center text-[11.5px] text-slate-400">Mall Insight — 社内利用限定</p>
        </div>
    </div>
</template>
