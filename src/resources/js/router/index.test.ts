import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { fetchCurrentUser } from "@/api/auth";

vi.mock("@/api/auth", () => ({ fetchCurrentUser: vi.fn(), login: vi.fn(), logout: vi.fn() }));

const USER = { id: 1, name: "在庫 担当", email: "zaiko@example.com" };

/** ルーターは作られた時点の状態を持つため、テストごとに読み込み直す。 */
async function loadRouter() {
    vi.resetModules();
    const { default: router } = await import("@/router");
    return router;
}

describe("router", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchCurrentUser).mockReset();
    });

    it("sends a user who is not logged in to the login screen and remembers where they were going", async () => {
        vi.mocked(fetchCurrentUser).mockResolvedValue(null);
        const router = await loadRouter();

        await router.push("/inventory-trends?view=graph");

        expect(router.currentRoute.value.name).toBe("login");
        expect(router.currentRoute.value.query.redirect).toBe("/inventory-trends?view=graph");
        expect(fetchCurrentUser).toHaveBeenCalledOnce();
    });

    it("opens screens for a logged-in user and skips the login screen", async () => {
        vi.mocked(fetchCurrentUser).mockResolvedValue(USER);
        const router = await loadRouter();

        await router.push("/inventory-trends");
        expect(router.currentRoute.value.name).toBe("inventory-trends");

        await router.push("/login?redirect=/forecasts");
        expect(router.currentRoute.value.name).toBe("forecasts");
    });
});
