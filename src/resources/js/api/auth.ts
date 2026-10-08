import { ApiError, apiClient } from "@/api/client";
import type { AuthUser } from "@/types/api";

/** ログインしている利用者。ログインしていなければ null。 */
export async function fetchCurrentUser(): Promise<AuthUser | null> {
    try {
        const { data } = await apiClient.get<{ data: AuthUser }>("/user");
        return data.data;
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            return null;
        }
        throw error;
    }
}

/** メールアドレスとパスワードでログインする。違っていれば入力エラー（422）になる。 */
export async function login(email: string, password: string): Promise<AuthUser> {
    const { data } = await apiClient.post<{ data: AuthUser }>("/login", { email, password });
    return data.data;
}

export async function logout(): Promise<void> {
    await apiClient.post("/logout");
}
