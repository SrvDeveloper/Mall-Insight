import { apiClient } from "@/api/client";
import type { ItemUnitPricePayload, ItemUnitPriceResponse } from "@/types/api";

/** 全品番の登録した単価と、直近12か月の平均単価（K-063）。 */
export async function fetchItemUnitPrices(): Promise<ItemUnitPriceResponse> {
    const { data } = await apiClient.get<ItemUnitPriceResponse>("/item-unit-prices");
    return data;
}

/** 品番の単価（全体・Amazon・BOSS、税込）を登録する。null の単価は登録を消し、販売実績の平均単価を使う。 */
export async function saveItemUnitPrices(itemNo: string, payload: ItemUnitPricePayload): Promise<void> {
    await apiClient.put(`/item-unit-prices/${encodeURIComponent(itemNo)}`, payload);
}
