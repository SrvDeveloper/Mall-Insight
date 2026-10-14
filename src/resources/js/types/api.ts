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

/** どの対象品番か（全体・Amazon・BOSS、B-136・K-129）。 */
export type SelectionScope = "total" | "amazon" | "boss";

/**
 * 画面に出す対象品番の説明（B-136、K-129・K-130）。全体は Amazon・BOSS の対象品番を合わせたもの。
 * 確定日時は、全体は Amazon・BOSS の新しい方（どちらも未確定なら null）。uses_candidates：未確定のチャネルがあり、そのチャネルの売上上位の品番を使っている。
 */
export interface TargetSelectionMeta {
    scope: SelectionScope;
    confirmed_at: string | null;
    item_count: number;
    uses_candidates: boolean;
}

export interface ItemRanking {
    data: ItemRank[];
    meta: { scope: SelectionScope; ranking_from: string; ranking_to: string; candidate_rank: number; max_items: number };
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
    scope: SelectionScope;
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
    /**
     * item_nos：画面に出す対象品番（未確定なら売上上位の品番、uses_candidates）。全体は確定せず、Amazon・BOSS を合わせたもので、
     * amazon_item_nos・boss_item_nos とそれぞれが未確定かを添える（K-130）。
     */
    meta: {
        scope: SelectionScope;
        item_nos: string[];
        uses_candidates: boolean;
        amazon_item_nos?: string[];
        boss_item_nos?: string[];
        amazon_uses_candidates?: boolean;
        boss_uses_candidates?: boolean;
        fiscal_year_start: string;
    };
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
    /** 平均日販から除いたイベントの日数（B-129、K-112）。欠品していたイベントの日は欠品日に数える */
    event_days: number;
    /** 欠品日とイベントの日を除いた販売数 */
    sales_quantity: number;
    /** 季節係数を登録した品番（B-106）では、期間の季節係数の平均。平均日販はこれで割り戻した値。登録していなければ null */
    seasonal_factor: number | null;
    average: number | null;
    base_weight: number;
    weight: number;
    is_excluded: boolean;
}

/** 1か月のうち、イベントの期間の日に倍率で増やした需要（K-112）。増やした数＝基準平均日販×（倍率－1）×日数。 */
export interface MonthlyEventDemand {
    period_id: number;
    event_id: number;
    name: string;
    channel: "boss" | "amazon";
    days: number;
    multiplier: number;
    /** システムの候補を使った（false なら担当者が入れた倍率） */
    is_candidate: boolean;
    quantity: number;
}

export interface MonthlyDemand {
    /** YYYY-MM */
    month: string;
    days: number;
    /** 基準平均日販×日数に、イベントで増やした数を足したもの */
    quantity: number;
    event_quantity: number;
    /** 登録した季節係数（B-106）。登録していなければ null */
    seasonal_factor: number | null;
    events: MonthlyEventDemand[];
}

/** SKU1件・モール1つの基準平均日販（B-134、K-126）。チャネルの基準平均日販は、モールの基準平均日販の合計。 */
export interface MallForecast {
    mall: "rakuten" | "yahoo" | "au_pay" | "amazon";
    mall_label: string;
    base_average: number | null;
    unpredictable_reason: "insufficient_days" | "stockout" | "no_sales" | null;
    unpredictable_reason_label: string | null;
    windows: AverageWindow[];
}

/** SKU1件・チャネル1つの需要予測（K-038）。予測不能のときは base_average・monthly が null で、理由を持つ。 */
export interface ChannelForecast {
    channel: "boss" | "amazon";
    channel_label: string;
    base_average: number | null;
    unpredictable_reason: "insufficient_days" | "stockout" | "no_sales" | null;
    unpredictable_reason_label: string | null;
    /** モールが1つのチャネル（Amazon）の期間。BOSS は空で、期間はモールごとに malls に持つ */
    windows: AverageWindow[];
    malls: MallForecast[];
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
        selection: TargetSelectionMeta | null;
        /** 全体・Amazon・BOSS ごとの対象品番（B-136、K-129）。画面で切り替えたときに、その対象品番だけを出す */
        target_items?: Record<SelectionScope, string[]>;
        /** 全体・Amazon・BOSS ごとの、画面に出す対象品番の選定（uses_total：未確定で全体の対象品番を使っている） */
        target_selections?: Record<SelectionScope, TargetSelectionMeta | null>;
        window_weights: { length: number; weight: number }[];
        min_sales_days: number;
        /** 登録したイベント（平均日販から日を除き、これからの日に倍率を掛けたもの） */
        /** 登録したイベントの期間 */
        events: { id: number; event_id: number; name: string; starts_on: string; ends_on: string; channels: ("boss" | "amazon")[]; status: SalesEventStatus }[];
        /** 季節係数を登録した品番 */
        seasonal_item_nos: string[];
    };
}

/** 需要予測の画面（B-131、K-123）の月・日。past は過去、current は今月（今日）、future はこれから。 */
export interface ForecastTimelineColumn {
    /** 月ごとは YYYY-MM、日ごとは YYYY-MM-DD */
    key: string;
    kind: "past" | "current" | "future";
    days: number;
}

/** SKU・チャネルの、月・日ごとの値（columns の順）。販売実績を取り込んでいない・予測できない月・日は null。 */
export interface ForecastTimelineChannel {
    /** 今日時点の基準平均日販（予測できなければ null） */
    base_average: number | null;
    /** 販売実績（今月は前日まで） */
    actual: (number | null)[];
    /** 販売実績の金額（税込） */
    actual_amount: (number | null)[];
    /** 欠品（月ごとは欠品した日数、日ごとは欠品したか）。在庫の記録が無ければ null */
    stockout: (number | boolean | null)[];
    /** 過去の月・日の、予測し直した数 */
    replayed: (number | null)[];
    /** 今月（今日）から先の需要予測 */
    forecast: (number | null)[];
    /** 今月（今日）から先の需要予測のうち、イベントで増やした数 */
    event: (number | null)[];
}

export interface ForecastTimelineSku {
    sku_id: number;
    sku_code: string;
    color_no: string | null;
    size: string | null;
    base_average: number | null;
    is_partial: boolean;
    unpredictable_reason_label: string | null;
    unit_prices: { total: number | null; boss: number | null; amazon: number | null };
    /** 売っているチャネルだけ（子ASINの無いSKUは Amazon を持たない） */
    channels: Partial<Record<"boss" | "amazon", ForecastTimelineChannel>>;
    /** 品番を指定したときだけ：予測の根拠（平均日販の期間・月別の内訳） */
    detail?: Omit<SkuForecast, "unit_prices">;
}

export interface ForecastTimelineItem {
    item_no: string;
    brand: string;
    category: string;
    skus: ForecastTimelineSku[];
}

export interface ForecastTimelineResponse {
    data: ForecastTimelineItem[];
    meta: DemandForecastResponse["meta"] & {
        unit: SalesUnit;
        /** 予測し直す起点（何か月前の1日か） */
        offset: number;
        offsets: number[];
        max_future_days: number;
        columns: ForecastTimelineColumn[];
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
        selection: TargetSelectionMeta | null;
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

/** 月ごとの欠品日数。判定できる在庫記録が無ければ days は null（K-084）。 */
export interface StockoutPeriod {
    days: number | null;
    known_days: number;
    period_days: number;
}

/** 販売実績のモールごとの値（列ごと、データの無い列は null）。 */
export interface ActualChannelSales {
    quantities: (number | null)[];
    amounts: (number | null)[];
    previous?: { quantities: (number | null)[]; amounts: (number | null)[] };
    last_year?: { quantities: (number | null)[]; amounts: (number | null)[] };
    stockouts?: StockoutPeriod[];
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
    /**
     * 販売実績のときだけ、モール（全体ならBOSS・Amazon、モールを選んだらそのモール）ごとの内訳。グラフの積み上げに使う。
     * previous は前の期間（前月・前日）、last_year は前年の同じ期間の値で、グラフのツールチップの前月比・前年比に使う（K-082）。比べられない列は null
     */
    channels?: Partial<Record<"boss" | "amazon", ActualChannelSales>>;
    /** 在庫実績のときだけ、在庫を取得していない日の推定在庫（K-087）。推定していない日は null */
    estimates?: (StockEstimate | null)[];
}

/** 推定在庫。直前に在庫を取得した日の在庫（base_quantity）から、その日から前日までの販売数（sold）を引き、0未満にしない（K-087）。 */
export interface StockEstimate {
    quantity: number;
    base_quantity: number;
    sold: number;
}

/** 在庫を取得していない日を推定できたか。no_base は在庫の履歴より前、no_sales_data は推定に使う日の販売実績を取り込んでいない。 */
export type StockEstimateStatus = "estimated" | "no_base" | "no_sales_data";

/** 在庫実績の日。在庫を取得していない日は、推定できたかと、推定の元にした在庫基準日を持つ。 */
export interface StockActualDate {
    date: string;
    has_data: boolean;
    estimate: StockEstimateStatus | null;
    estimate_base_date: string | null;
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
    selection: TargetSelectionMeta | null;
}

/** 品番別売上を何ごとに集めるか（B-127）。月ごとは直近12か月＋今月、日ごとは直近30日。 */
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

/** 店舗売上で集める商品。all は全商品（CrossWalkerに未登録のSKUを含む）、target は対象品番だけ（K-088）。 */
export type StoreSalesProducts = "all" | "target";

/** 店舗（楽天市場・Yahoo!ショッピング・au PAY マーケット・Amazon）の販売数と金額。販売実績を取り込んでいない期間は null。 */
export interface StoreSales {
    mall: "rakuten" | "yahoo" | "au_pay" | "amazon";
    label: string;
    channel: "boss" | "amazon";
    /** 期間ごとの、その店舗の販売実績がそろっているか */
    coverage: ("full" | "partial" | "none")[];
    quantities: (number | null)[];
    amounts: (number | null)[];
    /** 前の期間（前月・前日）と前年の同じ期間の値（K-082）。比べられない期間は null */
    previous: { quantities: (number | null)[]; amounts: (number | null)[] };
    last_year: { quantities: (number | null)[]; amounts: (number | null)[] };
}

/** 店舗売上（B-126）。期間と単位は品番別売上と同じ（K-080）。 */
export interface StoreSalesResponse {
    data: StoreSales[];
    meta: Pick<SalesActualsResponse["meta"], "calculated_at" | "selection" | "unit" | "from" | "to" | "periods" | "channels"> & { products: StoreSalesProducts };
}

/** 店舗売上の月・日の欄の、売上上位の品番（20件、K-091・K-096）。CrossWalkerに未登録のSKUは品番の順位に入れず unregistered にまとめる。 */
export interface StoreRankingResponse {
    data: {
        items: (StoreRankingRow & { item_no: string; brand: string; category: string; sku_count: number; stockouts: Partial<Record<"boss" | "amazon", RankingStockout & { skus: number }>> })[];
        unregistered: { sku_count: number; quantity: number; amount: number };
    };
    meta: {
        unit: SalesUnit;
        period: string;
        /** 集めた期間（今月・今日は今日まで） */
        from: string;
        to: string;
        mall: StoreSales["mall"] | null;
        mall_label: string;
        products: StoreSalesProducts;
        order: "amount" | "quantity";
        total: { quantity: number; amount: number };
        /** 順位の変動を比べる前の期間（前月・前日）。販売実績を取り込んでいなければ available は false */
        previous: { from: string; to: string; available: boolean };
        /** 欠品日数を数えるチャネル（店舗のチャネル。全店舗なら BOSS と Amazon） */
        stockout_channels: { channel: "boss" | "amazon"; label: string }[];
    };
}

/** 売上上位の行（K-091・K-095）。previous_rank は前の期間の順位（前の期間に売れていなければ null）。 */
interface StoreRankingRow {
    quantity: number;
    amount: number;
    rank: number;
    previous_rank: number | null;
    stockouts: Partial<Record<"boss" | "amazon", RankingStockout>>;
}

/** 期間の欠品日数（K-037・K-038）。在庫の記録が無ければ days は null。品番はSKUの欠品日数の合計で、skus は欠品したSKUの数。 */
export interface RankingStockout {
    days: number | null;
    known_days: number;
    period_days: number;
}

/** 日ごとの在庫数（直近31日）。在庫を取得していない日は、販売実績から推定した在庫を SKU の estimates に持つ（K-087）。 */
export interface StockActualsResponse {
    data: ActualItem[];
    meta: ActualMeta & {
        scope_warehouses: { warehouse: string; label: string }[];
        dates: StockActualDate[];
    };
}

/** 予測精度の測定（B-130）で測る何か月先。 */
export type BacktestHorizon = "month_1" | "months_3" | "check_month";

/** 予測精度の測定で比べる方式。seasonal が今の方式（イベント＋季節係数）、event_adjusted はイベントの補正だけ、base_average は補正を入れる前の基準平均日販。 */
export type BacktestMethod = "seasonal" | "event_adjusted" | "base_average" | "recent_30" | "last_year";

/** 予測精度を測った記録。 */
export interface ForecastBacktest {
    id: number;
    measured_at: string;
    measured_by: string | null;
    check_month_offset: number;
    item_count: number;
    sku_count: number;
    duration_ms: number;
    horizons: { horizon: BacktestHorizon; label: string; origin_from: string | null; origin_to: string | null; origin_count: number }[];
}

/** 方式ごとの当たり具合。販売実績の合計が0なら wape・bias は null。 */
/** 予測と販売実績の差の集計。販売実績の合計が0なら wape・bias・item_wape は null。 */
export interface BacktestErrors {
    actual: number;
    forecast: number;
    abs_error: number;
    wape: number | null;
    bias: number | null;
    /** 品番の合計で比べた外れ率（SKUどうしの偶然のぶれが打ち消し合う、K-124） */
    item_wape: number | null;
}

export interface BacktestMetrics extends BacktestErrors {
    evaluated: number;
    skipped: number;
    /** イベントの日と普段の日に分けた値（B-133、K-125）。分ける前に測った記録では null */
    event: BacktestErrors | null;
    normal: BacktestErrors | null;
}

/** 測った結果を何か月先とチャネルで絞って集計したもの。 */
export interface ForecastBacktestSummaryResponse {
    data: {
        methods: (BacktestMetrics & { method: BacktestMethod; label: string })[];
        items: { item_no: string; brand: string | null; category: string | null; methods: Record<BacktestMethod, BacktestMetrics> }[];
        origins: { origin: string; methods: Record<BacktestMethod, BacktestMetrics> }[];
    };
    meta: { backtest: ForecastBacktest; horizon: BacktestHorizon; channel: "total" | "boss" | "amazon" };
}

/** イベント（B-129）の、今日から見た状態。 */
export type SalesEventStatus = "upcoming" | "ongoing" | "ended";

/** イベントの期間（B-129、K-114）。 */
export interface SalesEventPeriod {
    id: number;
    event_id: number;
    starts_on: string;
    ends_on: string;
    days: number;
    note: string | null;
    status: SalesEventStatus;
    status_label: string;
    updated_by: string | null;
    updated_at: string | null;
}

/** イベント（キャンペーン、B-129、K-112・K-114）。名前・モール・メモ。期間はいくつも持つ。 */
export interface SalesEvent {
    id: number;
    name: string;
    malls: { value: "rakuten" | "yahoo" | "au_pay" | "amazon"; label: string }[];
    channels: ("boss" | "amazon")[];
    note: string | null;
    created_by: string | null;
    updated_by: string | null;
    updated_at: string | null;
}

/** 一覧に添える、期間・チャネルごとの倍率（品番の全体）。これからの期間は候補、終わった期間は普段の何倍売れたか。出せなければ null。 */
export interface SalesEventChannelMultiplier {
    channel: "boss" | "amazon";
    label: string;
    kind: "candidate" | "measured";
    multiplier: number | null;
    /** 根拠の回数に応じて控える前の候補（B-135、K-127・K-128）。終わった期間は null */
    raw_multiplier: number | null;
    /** 候補を求めた過去の期間の数 */
    basis_count: number;
    /** 担当者が倍率を入れた品番の数 */
    override_count: number;
}

export type SalesEventPeriodSummary = SalesEventPeriod & { multipliers: SalesEventChannelMultiplier[] };

/** イベントの一覧の1行。倍率は次の期間と最後に終わった期間だけ持つ（期間ごとの倍率はイベント1つの取得で出す）。 */
export interface SalesEventSummary extends SalesEvent {
    period_count: number;
    /** 次の期間（開催中かこれから） */
    next_period: SalesEventPeriodSummary | null;
    /** 最後に終わった期間 */
    last_period: SalesEventPeriodSummary | null;
    upcoming_count: number;
    ended_count: number;
}

/** イベントと、その期間（初日の順）。 */
export interface SalesEventWithPeriods extends SalesEventSummary {
    periods: SalesEventPeriodSummary[];
}

export interface SalesEventListResponse {
    data: SalesEventSummary[];
    meta: SalesEventMeta;
}

export interface SalesEventResponse {
    data: SalesEventWithPeriods;
    meta: SalesEventMeta;
}

export interface SalesEventMeta {
    /** 候補に使う過去の期間の数（新しい方から） */
    recent_events: number;
    /** 品番の倍率を候補にするのに必要な、普段なら売れた数の合計 */
    min_expected: number;
    max_days: number;
}

/** これからの期間の品番1つの倍率。usual は今日以降の期間の日の普段の予測、extra は倍率で増やした数。 */
export interface SalesEventCandidateItem {
    item_no: string;
    brand: string;
    category: string;
    candidate: number | null;
    /** 根拠の回数に応じて控える前の候補 */
    candidate_raw: number | null;
    uses_overall: boolean;
    past_expected: number | null;
    past_actual: number | null;
    override: number | null;
    multiplier: number | null;
    base_average: number | null;
    usual: number | null;
    extra: number | null;
}

/** 終わった期間の品番1つの、普段なら売れた数と売れた数。 */
export interface SalesEventMeasuredItem {
    item_no: string;
    brand: string;
    category: string;
    expected: number | null;
    actual: number | null;
    ratio: number | null;
}

/** 候補を求めた、同じイベントの過去の期間。 */
export interface SalesEventBasis {
    id: number;
    name: string;
    note: string | null;
    starts_on: string;
    ends_on: string;
    expected: number;
    actual: number;
    ratio: number | null;
}

export type SalesEventChannelDetail =
    | {
          channel: "boss" | "amazon";
          label: string;
          kind: "candidate";
          overall: number | null;
          /** 根拠の回数に応じて控える前の全体の候補 */
          overall_raw: number | null;
          expected: number;
          actual: number;
          basis: SalesEventBasis[];
          note: null;
          items: SalesEventCandidateItem[];
      }
    | {
          channel: "boss" | "amazon";
          label: string;
          kind: "measured";
          overall: number | null;
          expected: number | null;
          actual: number | null;
          basis: [];
          note: string | null;
          items: SalesEventMeasuredItem[];
      };

/** 期間1つの、チャネル・品番ごとの倍率。 */
export interface SalesEventPeriodDetailResponse {
    data: SalesEventPeriod & { event: SalesEvent; remaining_days: number; channels: SalesEventChannelDetail[] };
    meta: SalesEventMeta;
}

export interface SalesEventPeriodPayload {
    starts_on: string;
    ends_on: string;
    note: string | null;
}

export interface SalesEventPayload {
    name: string;
    malls: string[];
    note: string | null;
    /** 登録するときに一緒に入れる最初の期間 */
    period?: SalesEventPeriodPayload | null;
}

/** 販売実績から求めた季節係数（B-106、K-113）。factors は1月～12月。 */
export interface SeasonalIndexSummary {
    source: "item" | "category" | "overall" | null;
    source_label: string | null;
    factors: number[] | null;
    /** 月ごとの、比べた年数 */
    years: number[];
    /** 月平均の販売数 */
    monthly_quantity: number;
    /** 初期値に使える（12か月求められ、月平均の販売数が足りる） */
    is_usable: boolean;
}

/** 登録した季節係数の記録。factors が null なら登録をやめた記録。 */
export interface SeasonalFactorRecord {
    id: number;
    factors: number[] | null;
    initial_factors: number[] | null;
    initial_source: "item" | "category" | "overall" | null;
    note: string | null;
    changed_by: string | null;
    changed_at: string | null;
}

export interface SeasonalFactorMeta {
    min_factor: number;
    max_factor: number;
    min_monthly_quantity: number;
}

export interface SeasonalFactorListResponse {
    data: { item_no: string; brand: string; category: string; current: SeasonalFactorRecord | null; initial: SeasonalIndexSummary | null }[];
    meta: SeasonalFactorMeta;
}

export interface SeasonalFactorDetailResponse {
    data: {
        item_no: string;
        brand: string;
        category: string;
        is_target: boolean;
        current: SeasonalFactorRecord | null;
        initial: SeasonalIndexSummary | null;
        /** 品番・カテゴリー・対象品番の全体で求めた係数 */
        levels: SeasonalIndexSummary[];
        /** 年ごとの1月～12月の販売数（全チャネル）。取り込んでいない月は null */
        sales: { year: number; months: (number | null)[] }[];
        history: SeasonalFactorRecord[];
    };
    meta: SeasonalFactorMeta;
}
