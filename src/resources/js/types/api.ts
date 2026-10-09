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
    /** 金額の概算（B-123）に使う単価（税込）。合計はモールの単価を基準平均日販で加重平均した単価。出せなければ null */
    unit_prices: { total: number | null; boss: number | null; amazon: number | null };
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

/** 入荷前の入荷予定のまとめ（絞り込みによらない）と入荷済みの件数。 */
export interface InboundPlanSummary {
    pending_count: number;
    pending_quantity: number;
    unallocated_quantity: number;
    overdue_count: number;
    received_count: number;
}

export interface InboundPlanPage extends Paginated<InboundPlan> {
    meta: Paginated<InboundPlan>["meta"] & { summary: InboundPlanSummary };
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

/**
 * SKU1件の12か月在庫推移。計算できないSKUは months が null で、status_label に理由を持つ。
 * 販売試算で途中の月から計算できないとき（翌年度の目標が無いなど、K-109）は、months は計算できた月までで、
 * uncalculated_months_label にその後の月の理由を持つ。
 */
export interface SkuTrend {
    sku_id: number;
    sku_code: string;
    status: "calculated" | "unpredictable" | "not_sold" | "no_sales_target" | "no_stock";
    status_label: string;
    opening_stock: number | null;
    stock_by_warehouse: { warehouse: string; label: string; quantity: number | null }[] | null;
    average_daily: number | null;
    is_partial_forecast: boolean;
    forecast_reason_label: string | null;
    /** undetermined は、判定する月を計算できない（販売試算） */
    warning: "none" | "shortage" | "undetermined";
    warning_label: string;
    uncalculated_months_label: string | null;
    first_shortage_month: string | null;
    months: MonthTrend[] | null;
}

/** 在庫推移の需要に何を使うか（B-122）。需要予測（初期値）か、年間販売目標どおりに売れた場合の販売試算か。 */
export type DemandBasis = "forecast" | "sales_target";

/** 在庫推移をどの在庫で見るか（B-121）。全体は6区分の合計、Amazon・BOSSはそのモールの在庫の区分と需要。 */
export type TrendScope = "total" | "amazon" | "boss";

export interface ItemTrend {
    item_no: string;
    brand: string;
    category: string;
    /** 需要の出せるSKUが無く、仮に割り振れなかった入荷予定の数 */
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
        basis: DemandBasis;
        basis_label: string;
        scope: TrendScope;
        scope_label: string;
        /** 月初在庫に数える在庫の区分 */
        scope_warehouses: { warehouse: string; label: string }[];
        /** 推移の12か月（今月から） */
        months: { month: string; days: number }[];
        stock_date: string | null;
        forecast_from: string;
        sales_channels: { channel: "boss" | "amazon"; label: string; sales_data_to: string | null }[];
        selection: { confirmed_at: string; item_count: number } | null;
        settings: InventoryTrendSettings;
        /** 判定する月（YYYY-MM） */
        check_month: string;
        overdue_inbound_count: number;
        /** 販売試算のとき、推移の月が入る年度ごとの、販売目標を割り振れた対象品番の数（from・to は YYYY-MM） */
        sales_targets: { fiscal_year: number; from: string; to: string; calculated_item_count: number }[] | null;
    };
}

/** ログインしている利用者（B-009）。 */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
}

/** 区分1つの月別販売比率（4月～翌3月、1万分率。合計は10000）。 */
export interface MonthlySalesRatio {
    ratio_category_id: number;
    ratios: number[];
    source: "screen" | "excel";
    source_label: string;
    changed_by: string | null;
    changed_at: string;
}

/** 年間販売目標の1つの版。monthly_quantities は手で直した月（直していない月は null）、sku_quantities は手で上書きしたSKUの年間の数。 */
export interface SalesTarget {
    id: number;
    fiscal_year: number;
    item_no: string;
    annual_quantity: number;
    monthly_quantities: (number | null)[];
    sku_quantities: Record<string, number>;
    note: string | null;
    source: "screen" | "excel";
    source_label: string;
    changed_by: string | null;
    changed_at: string;
}

export interface SkuTargetPlan {
    sku_id: number;
    sku_code: string;
    /** 構成比に使う直近12か月の販売数 */
    sales_quantity: number;
    /** 品番の目標を構成比で割り振った数。構成比を出せなければ null */
    default_annual: number | null;
    override_annual: number | null;
    annual: number | null;
    monthly: number[] | null;
    /** 売上金額の概算に使う単価（税込）。登録が無く販売実績も無ければ null（K-062） */
    unit_price: number | null;
    price_basis: "registered" | "sku_actual" | "item_actual" | null;
    price_basis_label: string | null;
    annual_amount: number | null;
    monthly_amounts: number[] | null;
}

/** 品番1件の販売目標と、月・SKUへの割り振り（B-110）。割り振れないときは status_label に理由を持つ。 */
export interface ItemTargetPlan {
    item_no: string;
    brand: string | null;
    category: string | null;
    exists_in_crosswalker: boolean;
    is_target_item: boolean;
    ratio_category_id: number | null;
    ratio_category_name: string | null;
    /** 区分を登録しているか（false なら CrossWalker のカテゴリーから引いた初期値） */
    is_ratio_category_set: boolean;
    status: "calculated" | "no_target" | "no_category" | "no_ratio" | "no_composition";
    status_label: string;
    target: SalesTarget | null;
    monthly: number[] | null;
    month_overridden: boolean[];
    sku_total: number | null;
    /** 品番に登録した単価（税込）。登録が無ければ null で、販売実績の平均単価を使う（K-062） */
    unit_price: number | null;
    /** 品番全体の直近12か月の平均単価（税込） */
    actual_unit_price: number | null;
    /** 売上金額の概算（税込）。単価を出せないSKUは含めない */
    annual_amount: number | null;
    monthly_amounts: number[] | null;
    has_unpriced_sku: boolean;
    skus: SkuTargetPlan[];
}

export interface SalesTargetResponse {
    data: ItemTargetPlan[];
    meta: {
        fiscal_year: number;
        current_fiscal_year: number;
        /** 4月～翌3月（YYYY-MM） */
        months: string[];
        /** 月別販売比率の区分（並び順）。code は最初からある4区分の印で、画面で足した区分は null（K-061） */
        ratio_categories: { id: number; code: string | null; name: string; ratio: MonthlySalesRatio | null }[];
        composition_from: string;
        composition_to: string;
    };
}

export interface SalesTargetPayload {
    fiscal_year: number;
    item_no: string;
    annual_quantity: number;
    monthly_quantities: (number | null)[];
    sku_quantities: Record<number, number | null>;
    note: string | null;
}

/** 前年度から引き継げる年間販売目標（B-110、K-060）。current_annual_quantity は引き継ぐ年度の今の目標（無ければ null）。 */
export interface CarryOverCandidate {
    item_no: string;
    annual_quantity: number;
    monthly_override_count: number;
    sku_override_count: number;
    current_annual_quantity: number | null;
}

/** 品番の単価（B-110、K-063）。registered は登録した単価（税込）、actual は直近12か月の販売数と平均単価。 */
export interface ItemUnitPriceRow {
    item_no: string;
    brand: string;
    category: string;
    status: ActiveStatus;
    status_label: string;
    is_target_item: boolean;
    registered: {
        unit_price: number | null;
        amazon_unit_price: number | null;
        boss_unit_price: number | null;
        source_label: string;
        changed_by: string | null;
        changed_at: string;
    } | null;
    actual: Record<"all" | "amazon" | "boss", { quantity: number; unit_price: number | null }>;
}

export interface ItemUnitPriceResponse {
    data: ItemUnitPriceRow[];
    meta: { sales_from: string; sales_to: string };
}

export interface ItemUnitPricePayload {
    unit_price: number | null;
    amazon_unit_price: number | null;
    boss_unit_price: number | null;
}

/** 実績（B-124）のSKUの行。quantities は列（月・日）ごとの数で、データの無い列は null。 */
export interface ActualSku {
    sku_id: number;
    sku_code: string;
    /** CrossWalker の TQカラーNo（カラー×度数の表に使う） */
    color_no: string | null;
    /** CrossWalker の TQサイズ（度数×10。度数の無いSKUは "0"） */
    size: string | null;
    status: ActiveStatus | null;
    status_label: string | null;
    /** そのモールでは売っていない（子ASINの無いSKUのAmazon、K-042） */
    not_sold: boolean;
    quantities: (number | null)[];
    /** 販売実績のときだけ、列ごとの金額（税込） */
    amounts?: (number | null)[];
    /** 販売実績のときだけ、モール（全体ならBOSS・Amazon、モールを選んだらそのモール）ごとの内訳。グラフの積み上げに使う */
    channels?: Partial<Record<"boss" | "amazon", { quantities: (number | null)[]; amounts: (number | null)[] }>>;
}

export interface ActualItem {
    item_no: string;
    brand: string;
    category: string;
    skus: ActualSku[];
}

interface ActualMeta {
    calculated_at: string;
    scope: TrendScope;
    scope_label: string;
    selection: { confirmed_at: string; item_count: number } | null;
}

/** 品番別売上を何ごとに集めるか（B-127）。月ごとは直近12か月＋今月、日ごとは直近90日。 */
export type SalesUnit = "month" | "day";

/**
 * 販売数と金額。period は月ごとなら YYYY-MM、日ごとなら YYYY-MM-DD。coverage は販売実績がそろっているか
 * （none はデータ無し、partial は途中まで）。
 */
export interface SalesActualsResponse {
    data: ActualItem[];
    meta: ActualMeta & {
        unit: SalesUnit;
        /** 期間（月ごとは YYYY-MM、日ごとは YYYY-MM-DD） */
        from: string;
        to: string;
        periods: { period: string; coverage: "full" | "partial" | "none" }[];
        channels: { channel: "boss" | "amazon"; label: string; sales_data_from: string | null; sales_data_to: string | null }[];
    };
}

/** 日ごとの在庫数（直近31日）。has_data は在庫を取得した日か。 */
export interface StockActualsResponse {
    data: ActualItem[];
    meta: ActualMeta & {
        scope_warehouses: { warehouse: string; label: string }[];
        dates: { date: string; has_data: boolean }[];
    };
}
