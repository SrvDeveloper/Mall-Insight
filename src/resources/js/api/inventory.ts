import { apiClient } from "@/api/client";
import type { InventoryDate, Paginated, SyncState, ZeroStockViewSync } from "@/types/api";

export async function fetchLatestZeroStockViewSync(): Promise<SyncState<ZeroStockViewSync>> {
    const { data } = await apiClient.get<{ data: SyncState<ZeroStockViewSync> }>("/zerostockview-syncs/latest");
    return data.data;
}

/** ZeroStockView から日次在庫を取得する。取得に失敗した場合も、失敗の記録が返る。 */
export async function runZeroStockViewSync(): Promise<ZeroStockViewSync> {
    const { data } = await apiClient.post<{ data: ZeroStockViewSync }>("/zerostockview-syncs");
    return data.data;
}

export async function fetchInventoryDates(page: number): Promise<Paginated<InventoryDate>> {
    const { data } = await apiClient.get<Paginated<InventoryDate>>("/inventory-dates", { params: { page } });
    return data;
}
