import { apiClient } from "@/api/client";
import type { CrossWalkerSync, CrossWalkerSyncState } from "@/types/api";

export async function fetchLatestCrossWalkerSync(): Promise<CrossWalkerSyncState> {
    const { data } = await apiClient.get<{ data: CrossWalkerSyncState }>("/crosswalker-syncs/latest");
    return data.data;
}

/** CrossWalker から品番・SKUを取得する。取得に失敗した場合も、失敗の記録が返る。 */
export async function runCrossWalkerSync(): Promise<CrossWalkerSync> {
    const { data } = await apiClient.post<{ data: CrossWalkerSync }>("/crosswalker-syncs");
    return data.data;
}
