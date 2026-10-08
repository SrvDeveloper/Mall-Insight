import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { createMemoryHistory, createRouter } from "vue-router";
import LoginView from "@/views/LoginView.vue";
import { ApiError } from "@/api/client";
import { login } from "@/api/auth";
import { useAuthStore } from "@/stores/auth";

vi.mock("@/api/auth", () => ({ fetchCurrentUser: vi.fn(), login: vi.fn(), logout: vi.fn() }));

const USER = { id: 1, name: "在庫 担当", email: "zaiko@example.com" };

async function mountView(path: string) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/login", name: "login", component: LoginView },
            { path: "/:any(.*)", component: { template: "<div />" } },
        ],
    });
    await router.push(path);
    const wrapper = mount(LoginView, { global: { plugins: [router] } });
    return { wrapper, router };
}

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>["wrapper"], password = "correct-horse") {
    await wrapper.find('[data-testid="login-email"]').setValue("zaiko@example.com");
    await wrapper.find('[data-testid="login-password"]').setValue(password);
    await wrapper.find("form").trigger("submit");
    await flushPromises();
}

describe("LoginView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(login).mockReset();
    });

    it("logs in and goes back to the screen the user tried to open", async () => {
        vi.mocked(login).mockResolvedValue(USER);
        const { wrapper, router } = await mountView("/login?redirect=/inventory-trends?view=graph");

        expect(wrapper.find('[data-testid="login-submit"]').attributes("disabled")).toBeDefined();
        await fillAndSubmit(wrapper);

        expect(login).toHaveBeenCalledWith("zaiko@example.com", "correct-horse");
        expect(useAuthStore().user).toEqual(USER);
        expect(router.currentRoute.value.fullPath).toBe("/inventory-trends?view=graph");
    });

    it("does not go to another site after logging in", async () => {
        vi.mocked(login).mockResolvedValue(USER);
        const { wrapper, router } = await mountView("/login?redirect=//evil.example.com");

        await fillAndSubmit(wrapper);

        expect(router.currentRoute.value.fullPath).toBe("/");
    });

    it("shows why the login failed and clears the password", async () => {
        vi.mocked(login).mockRejectedValue(new ApiError("入力内容を確認してください。", 422, { email: ["メールアドレスまたはパスワードが正しくありません。"] }));
        const { wrapper, router } = await mountView("/login");

        await fillAndSubmit(wrapper, "wrong-password");

        expect(wrapper.find('[data-testid="login-error"]').text()).toBe("メールアドレスまたはパスワードが正しくありません。");
        expect((wrapper.find('[data-testid="login-password"]').element as HTMLInputElement).value).toBe("");
        expect(router.currentRoute.value.name).toBe("login");
    });

    it("tells that the login expired", async () => {
        const auth = useAuthStore();
        auth.user = USER;
        auth.expire();

        const { wrapper } = await mountView("/login");

        expect(wrapper.find('[data-testid="login-expired"]').text()).toBe("ログインの有効期限が切れました。もう一度ログインしてください。");
    });
});
