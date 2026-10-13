import { apiClient } from "@/api/client";
import type { SalesEvent, SalesEventDetailResponse, SalesEventListResponse, SalesEventPayload } from "@/types/api";

export async function fetchSalesEvents(): Promise<SalesEventListResponse> {
    const { data } = await apiClient.get<SalesEventListResponse>("/sales-events");
    return data;
}

export async function fetchSalesEvent(id: number): Promise<SalesEventDetailResponse> {
    const { data } = await apiClient.get<SalesEventDetailResponse>(`/sales-events/${id}`);
    return data;
}

export async function createSalesEvent(payload: SalesEventPayload): Promise<SalesEvent> {
    const { data } = await apiClient.post<{ data: SalesEvent }>("/sales-events", payload);
    return data.data;
}

export async function updateSalesEvent(id: number, payload: SalesEventPayload): Promise<SalesEvent> {
    const { data } = await apiClient.put<{ data: SalesEvent }>(`/sales-events/${id}`, payload);
    return data.data;
}

export async function deleteSalesEvent(id: number): Promise<void> {
    await apiClient.delete(`/sales-events/${id}`);
}

/** 担当者が入れた品番ごとの倍率を、送った内容で置き換える（送らなかった品番はシステムの候補を使う）。 */
export async function saveSalesEventMultipliers(id: number, multipliers: { item_no: string; channel: string; multiplier: number }[]): Promise<SalesEventDetailResponse> {
    const { data } = await apiClient.put<SalesEventDetailResponse>(`/sales-events/${id}/multipliers`, { multipliers });
    return data;
}
