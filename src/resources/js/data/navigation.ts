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
        pages: [{ name: "inventory-imports", label: "在庫の取得", path: "/imports/inventory" }],
    },
    {
        label: "商品",
        pages: [{ name: "items", label: "品番・SKU", path: "/items" }],
    },
];
