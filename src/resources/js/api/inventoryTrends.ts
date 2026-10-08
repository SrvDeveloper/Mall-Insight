import { apiClient } from "@/api/client";
import type { InventoryTrendResponse, InventoryTrendSettings } from "@/types/api";

/** 対象品番のSKUの12か月在庫推移。呼ぶたびに最新の在庫・需要予測・入荷予定から計算される。 */
export async function fetchInventoryTrends(): Promise<InventoryTrendResponse> {
    const { data } = await apiClient.get<InventoryTrendResponse>("/inventory-trends");
    return data;
}

/** 欠品を判定する月（今月から何か月後か）を変更する。次に在庫推移を計算するときから使われる。 */
export async function saveInventoryTrendSettings(checkMonthOffset: number): Promise<InventoryTrendSettings> {
    const { data } = await apiClient.post<{ data: InventoryTrendSettings }>("/inventory-trend-settings", { check_month_offset: checkMonthOffset });
    return data.data;
}
