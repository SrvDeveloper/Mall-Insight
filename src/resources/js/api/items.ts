import { apiClient } from "@/api/client";
import type { ActiveStatus, Item, Paginated } from "@/types/api";

export interface ItemQuery {
    keyword?: string;
    status?: ActiveStatus | "all";
    page?: number;
    per_page?: number;
}

export async function fetchItems(query: ItemQuery): Promise<Paginated<Item>> {
    const { data } = await apiClient.get<Paginated<Item>>("/items", { params: query });
    return data;
}

/** すべての品番（所属SKUを含む、品番コード順）。入力欄の選択肢に使う。 */
export async function fetchAllItems(): Promise<Item[]> {
    const items: Item[] = [];
    for (let page = 1; ; page++) {
        const response = await fetchItems({ page, per_page: 100 });
        items.push(...response.data);
        if (page >= response.meta.last_page) {
            return items;
        }
    }
}
