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

/** 品番に属していないSKU（B-010）。日付はいずれも保存済みのデータ全体から求めた最初と最後の日。 */
export interface UnregisteredSku {
    id: number;
    sku_code: string;
    recent_sales_quantity: number;
    first_sold_on: string | null;
    last_sold_on: string | null;
    first_stocked_on: string | null;
    last_stocked_on: string | null;
}

/** 未登録SKUの一覧。`sales_from`〜`sales_to` が販売数量の集計期間。 */
export interface UnregisteredSkuPage extends Paginated<UnregisteredSku> {
    meta: Paginated<UnregisteredSku>["meta"] & { sales_from: string; sales_to: string };
}

/** 品番の売上順位（B-005、K-032）。期間内に販売実績が無い品番は、売上金額・販売数・順位が null。 */
export interface ItemRank {
    item_id: number;
    item_no: string;
    brand: string;
    category: string;
    status: ActiveStatus;
    status_label: string;
    sku_count: number;
    sales_amount: number | null;
    sales_quantity: number | null;
    sales_rank: number | null;
    is_candidate: boolean;
}

export interface ItemRanking {
    data: ItemRank[];
    meta: { ranking_from: string; ranking_to: string; candidate_rank: number; max_items: number };
}

/** 選定に含めた品番と、確定時の売上順位。 */
export interface ItemSelectionItem {
    item_no: string;
    sales_rank: number | null;
    sales_amount: number | null;
    is_candidate: boolean;
    exists_in_crosswalker: boolean;
}

/** 対象品番の選定（確定1回分、K-030）。 */
export interface ItemSelection {
    id: number;
    confirmed_at: string;
    /** 確定した利用者の名前。ログイン（B-009）より前に確定した選定は null。 */
    confirmed_by: string | null;
    ranking_from: string;
    ranking_to: string;
    note: string | null;
    items: ItemSelectionItem[];
    /** 前回の選定からの変更（履歴の一覧でだけ返る）。初回の選定は null。 */
    changes?: { added: string[]; removed: string[] } | null;
}

export interface CurrentItemSelection {
    data: ItemSelection | null;
    meta: { fiscal_year_start: string };
}
