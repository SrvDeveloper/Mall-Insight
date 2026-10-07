import { apiClient } from "@/api/client";
import type { CurrentItemSelection, ItemRanking, ItemSelection, Paginated } from "@/types/api";

export async function fetchItemRanking(): Promise<ItemRanking> {
    const { data } = await apiClient.get<ItemRanking>("/item-ranking");
    return data;
}

export async function fetchCurrentItemSelection(): Promise<CurrentItemSelection> {
    const { data } = await apiClient.get<CurrentItemSelection>("/item-selections/current");
    return data;
}

/** 対象品番を確定する。新しい選定として記録される。 */
export async function confirmItemSelection(itemNos: string[], note: string | null): Promise<ItemSelection> {
    const { data } = await apiClient.post<{ data: ItemSelection }>("/item-selections", { item_nos: itemNos, note });
    return data.data;
}

/** 確定の履歴（新しい順）。 */
export async function fetchItemSelections(page: number): Promise<Paginated<ItemSelection>> {
    const { data } = await apiClient.get<Paginated<ItemSelection>>("/item-selections", { params: { page } });
    return data;
}
