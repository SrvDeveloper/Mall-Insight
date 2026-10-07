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

export interface CrossWalkerSync extends SyncRecordBase {
    item_count: number | null;
    sku_count: number | null;
    added_item_count: number | null;
    removed_item_count: number | null;
    detached_sku_count: number | null;
}

/** 外部システムからの取得記録の共通部分。 */
export interface SyncRecordBase {
    id: number;
    status: "succeeded" | "failed";
    status_label: string;
    triggered_by: "schedule" | "manual";
    triggered_by_label: string;
    started_at: string;
    finished_at: string;
    error_message: string | null;
}

export interface SyncState<T extends SyncRecordBase> {
    latest: T | null;
    last_succeeded: T | null;
}

export interface ZeroStockViewSync extends SyncRecordBase {
    from_date: string | null;
    to_date: string | null;
    stock_date_count: number | null;
    sku_row_count: number | null;
    created_sku_count: number | null;
    latest_stock_date: string | null;
}

export interface InventoryDate {
    stock_date: string;
    sku_count: number;
    fetched_at: string;
}

export interface SalesImportIssue {
    id: number;
    level: "error" | "warning";
    level_label: string;
    row_number: number | null;
    source_order_id: string | null;
    sku_code: string | null;
    message: string;
}

export interface SalesImport {
    id: number;
    source: string;
    file_name: string;
    status: "succeeded" | "failed";
    status_label: string;
    started_at: string;
    finished_at: string;
    row_count: number | null;
    created_line_count: number | null;
    updated_line_count: number | null;
    skipped_line_count: number | null;
    error_row_count: number | null;
    created_sku_count: number | null;
    warning_count?: number;
    sales_date_from: string | null;
    sales_date_to: string | null;
    error_message: string | null;
    issues?: SalesImportIssue[];
}
