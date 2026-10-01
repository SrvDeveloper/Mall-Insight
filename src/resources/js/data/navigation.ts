export interface NavPage {
    id: string;
    label: string;
    path: string;
}

export interface NavLink extends NavPage {
    icon: string;
}

export interface NavGroup {
    id: string;
    label: string;
    icon: string;
    pages: NavPage[];
}

export const topLevelPages: NavLink[] = [{ id: "PG-010", label: "ダッシュボード", icon: "home", path: "/dashboard" }];

export const navigation: NavGroup[] = [
    {
        id: "import",
        label: "取込",
        icon: "upload",
        pages: [
            { id: "PG-110", label: "販売実績取込", path: "/imports/sales-results" },
            { id: "PG-120", label: "商品在庫取込", path: "/imports/inventory" },
            { id: "PG-130", label: "取込履歴", path: "/imports/history" },
            { id: "PG-140", label: "取込指摘一覧", path: "/imports/issues" },
        ],
    },
    {
        id: "sales",
        label: "販売実績",
        icon: "bar-chart",
        pages: [
            { id: "PG-210", label: "販売実績一覧", path: "/sales-results" },
            { id: "PG-220", label: "販売実績手動修正", path: "/sales-results/adjustments" },
        ],
    },
    {
        id: "forecast",
        label: "需要予測",
        icon: "trending-up",
        pages: [
            { id: "PG-310", label: "需要予測一覧", path: "/forecasts" },
            { id: "PG-320", label: "手動予測補正", path: "/forecasts/manual-adjustment" },
            { id: "PG-330", label: "予測精度検証", path: "/forecasts/accuracy" },
        ],
    },
    {
        id: "target",
        label: "販売目標",
        icon: "target",
        pages: [
            { id: "PG-410", label: "年間販売目標入力・改訂", path: "/targets/annual" },
            { id: "PG-420", label: "目標改訂履歴", path: "/targets/history" },
        ],
    },
    {
        id: "compare",
        label: "比較",
        icon: "columns",
        pages: [
            { id: "PG-510", label: "販売目標・予測・実績比較", path: "/comparison" },
            { id: "PG-520", label: "品番・SKU詳細", path: "/comparison/detail" },
        ],
    },
    {
        id: "stock",
        label: "在庫",
        icon: "package_2",
        pages: [
            { id: "PG-610", label: "在庫照会", path: "/inventory/lookup" },
            { id: "PG-620", label: "在庫手入力", path: "/inventory/manual-entry" },
            { id: "PG-630", label: "入荷予定・補充実績管理", path: "/inventory/replenishment" },
        ],
    },
    {
        id: "simulate",
        label: "在庫試算",
        icon: "layers",
        pages: [
            { id: "PG-710", label: "対象品番選定", path: "/simulations/selection" },
            { id: "PG-720", label: "在庫試算実行", path: "/simulations/run" },
            { id: "PG-730", label: "12か月在庫推移", path: "/simulations/12-month-trend" },
            { id: "PG-740", label: "欠品・推奨発注一覧", path: "/simulations/shortages" },
            { id: "PG-750", label: "確定試算履歴・再表示", path: "/simulations/history" },
        ],
    },
    {
        id: "master",
        label: "マスタ",
        icon: "database",
        pages: [
            { id: "PG-830", label: "倉庫マスタ", path: "/masters/warehouses" },
            { id: "PG-840", label: "調達マスタ", path: "/masters/procurement" },
        ],
    },
    {
        id: "settings",
        label: "設定",
        icon: "sliders",
        pages: [
            { id: "PG-850", label: "パラメータ設定", path: "/settings/parameters" },
            { id: "PG-860", label: "利用者・権限", path: "/settings/users" },
        ],
    },
    {
        id: "audit",
        label: "監査",
        icon: "history",
        pages: [{ id: "PG-870", label: "変更履歴・監査ログ", path: "/audit-log" }],
    },
];
