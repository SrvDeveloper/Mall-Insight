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
    source: "boss" | "amazon";
    source_label: string;
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

/** 期間別平均日販（B-006、K-035・K-036）。日数が足りず除外した期間は平均日販が null で、重みは0。 */
export interface AverageWindow {
    length: number;
    /** 期間の末日が販売実績の最新日の何日前か。期間は重ならずに遡る。 */
    offset: number;
    /** 「直近30日」「31〜90日前」など */
    label: string;
    from: string | null;
    to: string | null;
    /** 販売できた日数（欠品日を除く、K-037） */
    sales_days: number;
    /** 欠品していた日数。AmazonとBOSSの在庫の合計が0以下の日 */
    stockout_days: number;
    /** 欠品日を除いた販売数 */
    sales_quantity: number;
    average: number | null;
    base_weight: number;
    weight: number;
    is_excluded: boolean;
}

export interface MonthlyDemand {
    /** YYYY-MM */
    month: string;
    days: number;
    quantity: number;
}

/** SKU1件・チャネル1つの需要予測（K-038）。予測不能のときは base_average・monthly が null で、理由を持つ。 */
export interface ChannelForecast {
    channel: "boss" | "amazon";
    channel_label: string;
    base_average: number | null;
    unpredictable_reason: "insufficient_days" | "stockout" | "no_sales" | null;
    unpredictable_reason_label: string | null;
    windows: AverageWindow[];
    monthly: MonthlyDemand[] | null;
}

/** SKU1件の需要予測。予測できたチャネルの合計。1つも予測できなければ base_average・monthly が null。 */
export interface SkuForecast {
    sku_id: number;
    sku_code: string;
    status: ActiveStatus | null;
    status_label: string | null;
    base_average: number | null;
    monthly: MonthlyDemand[] | null;
    /** 一部のチャネルだけ予測できなかった */
    is_partial: boolean;
    /** 予測できなかったチャネルの理由（「Amazon：直近180日に販売実績がありません」など） */
    unpredictable_reason_label: string | null;
    channels: ChannelForecast[];
}

export interface ItemForecast {
    item_no: string;
    brand: string;
    category: string;
    skus: SkuForecast[];
}

export interface DemandForecastResponse {
    data: ItemForecast[];
    meta: {
        calculated_at: string;
        /** チャネルごとの販売実績の期間。取り込んでいなければ null */
        channels: { channel: "boss" | "amazon"; label: string; sales_data_from: string | null; sales_data_to: string | null }[];
        forecast_from: string;
        selection: { confirmed_at: string; item_count: number } | null;
        window_weights: { length: number; weight: number }[];
        min_sales_days: number;
    };
}

/** 入荷予定のSKUへの割り振り。 */
export interface InboundPlanAllocation {
    sku_id: number;
    sku_code: string;
    quantity: number;
}

/** 入荷予定（B-007、K-043）。品番・月単位。割り振っていない残りは、在庫推移で需要予測の比率により仮に割り振る。 */
export interface InboundPlan {
    id: number;
    item_no: string;
    brand: string | null;
    category: string | null;
    exists_in_crosswalker: boolean;
    /** YYYY-MM */
    arrival_month: string;
    quantity: number;
    allocated_quantity: number;
    unallocated_quantity: number;
    note: string | null;
    received_at: string | null;
    /** 入荷予定月を過ぎても入荷済みになっていない */
    is_overdue: boolean;
    allocations: InboundPlanAllocation[];
}

export interface InboundPlanPayload {
    item_no: string;
    arrival_month: string;
    quantity: number;
    note: string | null;
    allocations: { sku_id: number; quantity: number }[];
}

/** SKU1件・1か月分の在庫推移（B-008、K-026）。provisional_inbound は未割り振りの入荷予定を仮に割り振った分（K-043）。 */
export interface MonthTrend {
    /** YYYY-MM */
    month: string;
    days: number;
    opening_stock: number;
    demand: number;
    inbound: number;
    provisional_inbound: number;
    ending_stock: number;
    shortfall: number;
}

/** SKU1件の12か月在庫推移。計算できないSKUは months が null で、status_label に理由を持つ。 */
export interface SkuTrend {
    sku_id: number;
    sku_code: string;
    status: "calculated" | "unpredictable" | "no_stock";
    status_label: string;
    opening_stock: number | null;
    stock_by_warehouse: { warehouse: string; label: string; quantity: number | null }[] | null;
    average_daily: number | null;
    is_partial_forecast: boolean;
    forecast_reason_label: string | null;
    warning: "none" | "shortage";
    warning_label: string;
    first_shortage_month: string | null;
    months: MonthTrend[] | null;
}

export interface ItemTrend {
    item_no: string;
    brand: string;
    category: string;
    /** 需要予測の出せるSKUが無く、仮に割り振れなかった入荷予定の数 */
    unassignable_inbound: number;
    skus: SkuTrend[];
}

/** 在庫推移の判定の基準（B-011）。changed_at は画面で変更した日時で、初期値のままなら null。 */
export interface InventoryTrendSettings {
    check_month_offset: number;
    changed_at: string | null;
}

export interface InventoryTrendResponse {
    data: ItemTrend[];
    meta: {
        calculated_at: string;
        stock_date: string | null;
        forecast_from: string;
        sales_channels: { channel: "boss" | "amazon"; label: string; sales_data_to: string | null }[];
        selection: { confirmed_at: string; item_count: number } | null;
        settings: InventoryTrendSettings;
        /** 判定する月（YYYY-MM） */
        check_month: string;
        overdue_inbound_count: number;
    };
}
