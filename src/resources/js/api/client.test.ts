import { describe, expect, it, vi } from "vitest";
import { AxiosError, AxiosHeaders, type InternalAxiosRequestConfig } from "axios";
import { ApiError, createApiClient, toApiError } from "@/api/client";

function responseError(status: number, data: unknown = {}): AxiosError {
    const config = { headers: new AxiosHeaders() } as InternalAxiosRequestConfig;
    return new AxiosError("Request failed", "ERR_BAD_RESPONSE", config, null, { status, statusText: "", headers: {}, config, data });
}

describe("toApiError", () => {
    it("treats an error without a response as a connection failure", () => {
        const error = toApiError(new AxiosError("Network Error", "ERR_NETWORK"));

        expect(error.status).toBeNull();
        expect(error.message).toBe("サーバーに接続できません。ネットワークの状態を確認してください。");
    });

    it("keeps the server message and field errors for 422", () => {
        const error = toApiError(responseError(422, { message: "表示件数は1～100の範囲で指定してください。", errors: { per_page: ["表示件数は1～100の範囲で指定してください。"] } }));

        expect(error.isValidationError).toBe(true);
        expect(error.message).toBe("表示件数は1～100の範囲で指定してください。");
        expect(error.errors).toEqual({ per_page: ["表示件数は1～100の範囲で指定してください。"] });
    });

    it("replaces a server exception message with a user-facing message for 500", () => {
        const error = toApiError(responseError(500, { message: "SQLSTATE[HY000] connection refused" }));

        expect(error.status).toBe(500);
        expect(error.message).toBe("サーバーでエラーが発生しました（500）。時間をおいてもう一度お試しください。");
    });

    it("uses a fixed message for 404", () => {
        expect(toApiError(responseError(404)).message).toBe("対象のデータが見つかりません。");
    });
});

describe("createApiClient", () => {
    it("reports non-validation errors and rejects with ApiError", async () => {
        const onError = vi.fn();
        const client = createApiClient(onError);
        client.defaults.adapter = () => Promise.reject(responseError(500));

        await expect(client.get("/items")).rejects.toBeInstanceOf(ApiError);
        expect(onError).toHaveBeenCalledOnce();
    });

    it("does not report validation errors globally", async () => {
        const onError = vi.fn();
        const client = createApiClient(onError);
        client.defaults.adapter = () => Promise.reject(responseError(422, { message: "入力内容を確認してください。", errors: {} }));

        await expect(client.get("/items")).rejects.toMatchObject({ status: 422 });
        expect(onError).not.toHaveBeenCalled();
    });
});
