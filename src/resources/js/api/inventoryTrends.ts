import { apiClient } from "@/api/client";
import type { DemandBasis, InventoryTrendResponse, InventoryTrendSettings, TrendScope } from "@/types/api";

/** 対象品番のSKUの12か月在庫推移。呼ぶたびに最新の在庫・需要（需要予測か販売目標）・入荷予定から計算される。scope で全体・Amazon・BOSSを選ぶ。 */
export async function fetchInventoryTrends(basis: DemandBasis = "forecast", scope: TrendScope = "total"): Promise<InventoryTrendResponse> {
    const { data } = await apiClient.get<InventoryTrendResponse>("/inventory-trends", { params: { basis, scope } });
    return data;
}

/** 欠品を判定する月（今月から何か月後か）を変更する。次に在庫推移を計算するときから使われる。 */
export async function saveInventoryTrendSettings(checkMonthOffset: number): Promise<InventoryTrendSettings> {
    const { data } = await apiClient.post<{ data: InventoryTrendSettings }>("/inventory-trend-settings", { check_month_offset: checkMonthOffset });
    return data.data;
}
