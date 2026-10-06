/** Laravel のページング付きリソースレスポンス。 */
export interface Paginated<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
}

export type ActiveStatus = "active" | "inactive";

export interface Sku {
    id: number;
    sku_code: string;
    child_asin: string | null;
    status: ActiveStatus | null;
    status_label: string | null;
    tq_item_no: string | null;
    tq_color_no: string | null;
    tq_size: string | null;
}

export interface Item {
    id: number;
    item_no: string;
    brand: string;
    category: string;
    parent_asin: string | null;
    status: ActiveStatus;
    status_label: string;
    crosswalker_updated_at: string | null;
    skus: Sku[];
}

export interface CrossWalkerSync {
    id: number;
    status: "succeeded" | "failed";
    status_label: string;
    triggered_by: "schedule" | "manual";
    triggered_by_label: string;
    started_at: string;
    finished_at: string;
    item_count: number | null;
    sku_count: number | null;
    added_item_count: number | null;
    removed_item_count: number | null;
    detached_sku_count: number | null;
    error_message: string | null;
}

export interface CrossWalkerSyncState {
    latest: CrossWalkerSync | null;
    last_succeeded: CrossWalkerSync | null;
}
