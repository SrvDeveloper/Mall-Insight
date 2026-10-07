import { apiClient } from "@/api/client";
import type { UnregisteredSkuPage } from "@/types/api";

export async function fetchUnregisteredSkus(page: number): Promise<UnregisteredSkuPage> {
    const { data } = await apiClient.get<UnregisteredSkuPage>("/unregistered-skus", { params: { page } });
    return data;
}
