import axios, { AxiosError, type AxiosInstance } from "axios";
import { useNotificationStore } from "@/stores/notifications";

/**
 * API呼び出しの失敗を表す。サーバーの返す `{ message, errors }` 形式に揃える。
 * status が null の場合はサーバーに到達できなかった（通信エラー）。
 */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number | null,
        readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = "ApiError";
    }

    get isValidationError(): boolean {
        return this.status === 422;
    }
}

const STATUS_MESSAGES: Record<number, string> = {
    401: "ログインの有効期限が切れました。もう一度ログインしてください。",
    403: "この操作を行う権限がありません。",
    404: "対象のデータが見つかりません。",
    409: "同じ処理を実行中です。終わるまでお待ちください。",
    419: "画面の有効期限が切れました。ページを再読み込みしてください。",
    429: "アクセスが集中しています。しばらく待ってからもう一度お試しください。",
};

/**
 * axios のエラーを ApiError に変換する。
 * 422 はサーバーの入力エラー文言をそのまま使い、それ以外は利用者向けの定型文にする
 * （サーバーの例外メッセージや英語の文言を画面に出さないため）。
 */
export function toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) {
        return error;
    }
    if (!(error instanceof AxiosError) || !error.response) {
        return new ApiError("サーバーに接続できません。ネットワークの状態を確認してください。", null);
    }

    const { status, data } = error.response;
    if (status === 422) {
        const body = (data ?? {}) as { message?: string; errors?: Record<string, string[]> };
        return new ApiError(body.message ?? "入力内容を確認してください。", status, body.errors ?? {});
    }

    return new ApiError(STATUS_MESSAGES[status] ?? `サーバーでエラーが発生しました（${status}）。時間をおいてもう一度お試しください。`, status);
}

export function createApiClient(onError: (error: ApiError) => void): AxiosInstance {
    const client = axios.create({
        baseURL: "/api/v1",
        headers: { Accept: "application/json" },
        withCredentials: true,
        withXSRFToken: true,
    });

    client.interceptors.response.use(
        (response) => response,
        (error: unknown) => {
            const apiError = toApiError(error);
            // 入力エラーは各画面のフォームで表示するため、全体通知には出さない
            if (!apiError.isValidationError) {
                onError(apiError);
            }
            return Promise.reject(apiError);
        },
    );

    return client;
}

let unauthorizedHandler: (() => void) | null = null;

/**
 * 401（ログインしていない・ログインの有効期限が切れた）を受けたときの処理を登録する（B-009）。
 * 401 は全体通知に出さず、この処理でログイン画面へ移す。
 */
export function onUnauthorized(handler: () => void): void {
    unauthorizedHandler = handler;
}

export const apiClient = createApiClient((error) => {
    if (error.status === 401) {
        unauthorizedHandler?.();
        return;
    }
    useNotificationStore().notifyError(error.message);
});
