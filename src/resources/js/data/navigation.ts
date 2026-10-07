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
        pages: [{ name: "items", label: "品番・SKU", path: "/items" }],
    },
    {
        label: "在庫試算",
        pages: [
            { name: "target-items", label: "対象品番", path: "/target-items" },
            { name: "forecasts", label: "需要予測", path: "/forecasts" },
            { name: "inbound-plans", label: "入荷予定", path: "/inbound-plans" },
        ],
    },
];
