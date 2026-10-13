export interface NavPage {
    name: string;
    label: string;
    path: string;
}

export interface NavGroup {
    label: string;
    pages: NavPage[];
}

/**
 * 左メニュー。業務の流れの順に並べ、作り終えた画面だけを載せる（バックログ B-001）。
 */
export const navigation: NavGroup[] = [
    {
        label: "取込",
        pages: [
            { name: "sales-imports", label: "販売実績の取込", path: "/imports/sales" },
            { name: "inventory-imports", label: "在庫の取得", path: "/imports/inventory" },
        ],
    },
    {
        label: "商品",
        pages: [
            { name: "items", label: "品番・SKU", path: "/items" },
            { name: "unit-prices", label: "単価", path: "/unit-prices" },
        ],
    },
    {
        label: "販売実績",
        pages: [
            { name: "item-sales", label: "品番別売上", path: "/item-sales" },
            { name: "store-sales", label: "店舗売上", path: "/store-sales" },
        ],
    },
    {
        label: "在庫試算",
        pages: [
            { name: "target-items", label: "対象品番", path: "/target-items" },
            { name: "forecasts", label: "需要予測", path: "/forecasts" },
            { name: "sales-events", label: "イベント", path: "/sales-events" },
            { name: "seasonal-factors", label: "季節係数", path: "/seasonal-factors" },
            { name: "forecast-accuracy", label: "予測精度", path: "/forecast-accuracy" },
            { name: "sales-targets", label: "販売目標", path: "/sales-targets" },
            { name: "inbound-plans", label: "入荷予定", path: "/inbound-plans" },
            { name: "inventory-trends", label: "在庫予測", path: "/inventory-trends" },
            { name: "stock-actuals", label: "在庫推移", path: "/stock-actuals" },
        ],
    },
];
