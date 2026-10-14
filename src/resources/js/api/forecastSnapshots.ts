import { apiClient } from "@/api/client";
import type { ForecastSnapshot } from "@/types/api";

/** 保存した需要予測の版と、その答え合わせ（新しい順、B-108）。 */
export async function fetchForecastSnapshots(): Promise<ForecastSnapshot[]> {
    const { data } = await apiClient.get<{ data: ForecastSnapshot[] }>("/forecast-snapshots");
    return data.data;
}

/** 今の需要予測を版として保存する（数秒かかる）。 */
export async function saveForecastSnapshot(): Promise<{ id: number; item_count: number; sku_count: number }> {
    const { data } = await apiClient.post<{ data: { id: number; item_count: number; sku_count: number } }>("/forecast-snapshots");
    return data.data;
}
