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
