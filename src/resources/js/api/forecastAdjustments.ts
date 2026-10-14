import { apiClient } from "@/api/client";
import type { ForecastAdjustment, ForecastAdjustmentListResponse, SaveForecastAdjustmentInput } from "@/types/api";

/** 品番の手動での予測補正（取り消した・終えた補正も含む、新しい順）。 */
export async function fetchForecastAdjustments(itemNo: string): Promise<ForecastAdjustmentListResponse> {
    const { data } = await apiClient.get<ForecastAdjustmentListResponse>("/forecast-adjustments", { params: { item_no: itemNo } });
    return data;
}

/** 補正を登録する。replaces を送ると、その補正を取り消して新しい補正を登録する。 */
export async function saveForecastAdjustment(input: SaveForecastAdjustmentInput): Promise<ForecastAdjustment> {
    const { data } = await apiClient.post<{ data: ForecastAdjustment }>("/forecast-adjustments", input);
    return data.data;
}

export async function cancelForecastAdjustment(id: number): Promise<ForecastAdjustment> {
    const { data } = await apiClient.post<{ data: ForecastAdjustment }>(`/forecast-adjustments/${id}/cancel`);
    return data.data;
}
