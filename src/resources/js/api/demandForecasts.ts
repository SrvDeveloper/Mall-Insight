import { apiClient } from "@/api/client";
import type { DemandForecastResponse, SkuForecast } from "@/types/api";

/** 対象品番のSKUの需要予測。呼ぶたびに最新の販売実績から計算される。 */
export async function fetchDemandForecasts(): Promise<DemandForecastResponse> {
    const { data } = await apiClient.get<DemandForecastResponse>("/demand-forecasts");
    return data;
}

/** 1つの品番のSKUの需要予測（対象品番でなくてもよい）。入荷予定の自動割り振りで、SKUの比率に使う（K-054）。 */
export async function fetchItemForecast(itemNo: string): Promise<SkuForecast[]> {
    const { data } = await apiClient.get<DemandForecastResponse>("/demand-forecasts", { params: { item_no: itemNo } });
    return data.data[0]?.skus ?? [];
}
