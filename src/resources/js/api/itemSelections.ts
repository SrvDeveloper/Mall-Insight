import { apiClient } from "@/api/client";
import type { CurrentItemSelection, ItemRanking, ItemSelection, Paginated, SelectionScope } from "@/types/api";

/** 売上順位。Amazon・BOSS はそのチャネルの販売実績で順位を付ける（B-136、K-129）。 */
export async function fetchItemRanking(scope: SelectionScope = "total"): Promise<ItemRanking> {
    const { data } = await apiClient.get<ItemRanking>("/item-ranking", { params: { scope } });
    return data;
}

export async function fetchCurrentItemSelection(scope: SelectionScope = "total"): Promise<CurrentItemSelection> {
    const { data } = await apiClient.get<CurrentItemSelection>("/item-selections/current", { params: { scope } });
    return data;
}

/** 対象品番を確定する。新しい選定として記録される。 */
export async function confirmItemSelection(itemNos: string[], note: string | null, scope: SelectionScope = "total"): Promise<ItemSelection> {
    const { data } = await apiClient.post<{ data: ItemSelection }>("/item-selections", { scope, item_nos: itemNos, note });
    return data.data;
}

/** 確定の履歴（新しい順）。 */
export async function fetchItemSelections(page: number, scope: SelectionScope = "total"): Promise<Paginated<ItemSelection>> {
    const { data } = await apiClient.get<Paginated<ItemSelection>>("/item-selections", { params: { page, scope } });
    return data;
}
