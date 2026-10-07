import { apiClient } from "@/api/client";
import type { DemandForecastResponse } from "@/types/api";

/** 対象品番のSKUの需要予測。呼ぶたびに最新の販売実績から計算される。 */
export async function fetchDemandForecasts(): Promise<DemandForecastResponse> {
    const { data } = await apiClient.get<DemandForecastResponse>("/demand-forecasts");
    return data;
}
