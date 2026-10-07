import { apiClient } from "@/api/client";
import type { InboundPlan, InboundPlanPayload, Paginated } from "@/types/api";

export interface InboundPlanQuery {
    keyword?: string;
    include_received?: boolean;
    page?: number;
}

export async function fetchInboundPlans(query: InboundPlanQuery): Promise<Paginated<InboundPlan>> {
    const { data } = await apiClient.get<Paginated<InboundPlan>>("/inbound-plans", { params: { ...query, include_received: query.include_received ? 1 : undefined } });
    return data;
}

export async function createInboundPlan(payload: InboundPlanPayload): Promise<InboundPlan> {
    const { data } = await apiClient.post<{ data: InboundPlan }>("/inbound-plans", payload);
    return data.data;
}

export async function updateInboundPlan(id: number, payload: InboundPlanPayload): Promise<InboundPlan> {
    const { data } = await apiClient.put<{ data: InboundPlan }>(`/inbound-plans/${id}`, payload);
    return data.data;
}

export async function deleteInboundPlan(id: number): Promise<void> {
    await apiClient.delete(`/inbound-plans/${id}`);
}

/** 入荷済みにする（received = false で取り消す）。 */
export async function setInboundPlanReceived(id: number, received: boolean): Promise<InboundPlan> {
    const { data } = received ? await apiClient.post<{ data: InboundPlan }>(`/inbound-plans/${id}/receipt`) : await apiClient.delete<{ data: InboundPlan }>(`/inbound-plans/${id}/receipt`);
    return data.data;
}
