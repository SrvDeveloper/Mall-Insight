import { apiClient } from "@/api/client";
import type { SalesEventListResponse, SalesEventPayload, SalesEventPeriod, SalesEventPeriodDetailResponse, SalesEventPeriodPayload, SalesEventResponse } from "@/types/api";

export async function fetchSalesEvents(): Promise<SalesEventListResponse> {
    const { data } = await apiClient.get<SalesEventListResponse>("/sales-events");
    return data;
}

/** イベント1つと、その期間・倍率。 */
export async function fetchSalesEvent(id: number): Promise<SalesEventResponse> {
    const { data } = await apiClient.get<SalesEventResponse>(`/sales-events/${id}`);
    return data;
}

/** イベントを登録する（最初の期間を一緒に入れられる）。 */
export async function createSalesEvent(payload: SalesEventPayload): Promise<SalesEventResponse> {
    const { data } = await apiClient.post<SalesEventResponse>("/sales-events", payload);
    return data;
}

export async function updateSalesEvent(id: number, payload: SalesEventPayload): Promise<SalesEventResponse> {
    const { data } = await apiClient.put<SalesEventResponse>(`/sales-events/${id}`, payload);
    return data;
}

/** イベントを、期間と担当者が入れた倍率ごと削除する。 */
export async function deleteSalesEvent(id: number): Promise<void> {
    await apiClient.delete(`/sales-events/${id}`);
}

export async function createSalesEventPeriod(eventId: number, payload: SalesEventPeriodPayload): Promise<SalesEventPeriod> {
    const { data } = await apiClient.post<{ data: SalesEventPeriod }>(`/sales-events/${eventId}/periods`, payload);
    return data.data;
}

export async function updateSalesEventPeriod(id: number, payload: SalesEventPeriodPayload): Promise<SalesEventPeriod> {
    const { data } = await apiClient.put<{ data: SalesEventPeriod }>(`/sales-event-periods/${id}`, payload);
    return data.data;
}

export async function deleteSalesEventPeriod(id: number): Promise<void> {
    await apiClient.delete(`/sales-event-periods/${id}`);
}

export async function fetchSalesEventPeriod(id: number): Promise<SalesEventPeriodDetailResponse> {
    const { data } = await apiClient.get<SalesEventPeriodDetailResponse>(`/sales-event-periods/${id}`);
    return data;
}

/** 期間の、担当者が入れた品番ごとの倍率を、送った内容で置き換える（送らなかった品番はシステムの候補を使う）。 */
export async function saveSalesEventPeriodMultipliers(id: number, multipliers: { item_no: string; channel: string; multiplier: number }[]): Promise<SalesEventPeriodDetailResponse> {
    const { data } = await apiClient.put<SalesEventPeriodDetailResponse>(`/sales-event-periods/${id}/multipliers`, { multipliers });
    return data;
}
