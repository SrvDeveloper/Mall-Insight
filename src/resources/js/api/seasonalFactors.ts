import { apiClient } from "@/api/client";
import type { SeasonalFactorDetailResponse, SeasonalFactorListResponse } from "@/types/api";

export async function fetchSeasonalFactors(): Promise<SeasonalFactorListResponse> {
    const { data } = await apiClient.get<SeasonalFactorListResponse>("/seasonal-factors");
    return data;
}

export async function fetchSeasonalFactor(itemNo: string): Promise<SeasonalFactorDetailResponse> {
    const { data } = await apiClient.get<SeasonalFactorDetailResponse>(`/seasonal-factors/${encodeURIComponent(itemNo)}`);
    return data;
}

/** 品番の季節係数（1月～12月）を登録する。factors を null にすると登録をやめる。 */
export async function saveSeasonalFactor(itemNo: string, factors: number[] | null, note: string | null): Promise<SeasonalFactorDetailResponse> {
    const { data } = await apiClient.put<SeasonalFactorDetailResponse>(`/seasonal-factors/${encodeURIComponent(itemNo)}`, { factors, note });
    return data;
}
