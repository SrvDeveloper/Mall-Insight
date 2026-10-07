import { apiClient } from "@/api/client";
import type { InventoryTrendResponse } from "@/types/api";

/** 対象品番のSKUの12か月在庫推移。呼ぶたびに最新の在庫・需要予測・入荷予定から計算される。 */
export async function fetchInventoryTrends(): Promise<InventoryTrendResponse> {
    const { data } = await apiClient.get<InventoryTrendResponse>("/inventory-trends");
    return data;
}
