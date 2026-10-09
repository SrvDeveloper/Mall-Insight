import type { SalesActualsResponse } from "@/types/api";

type Values = { quantities: (number | null)[]; amounts: (number | null)[] };
/** previous は前月、last_year は前年の同じ月（K-082）。 */
type Breakdown = Values & { previous?: Values; last_year?: Values };

/** モールごとの内訳。全体なら BOSS・Amazon、Amazon を選んだら Amazon だけ。 */
function channels(scope: "total" | "amazon", boss: Breakdown, amazon: Breakdown) {
    return scope === "amazon" ? { amazon } : { boss, amazon };
}

/**
 * 品番別売上・SKU別売上のテストで使う販売実績（2品番、3か月）。fl-01 は 9 月に BOSS 3・Amazon 1。si-13 は度数の無い品番。
 * fl-01 の比べる値：10月の前月（9月の同じ日まで）は BOSS 1・Amazon 1、前年の同じ月は BOSS 4（Amazon は取り込んでいない）。9月の前年は BOSS 2。
 */
export function salesResponse(scope: "total" | "amazon" = "total"): SalesActualsResponse {
    return {
        data: [
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [
                    {
                        sku_id: 1,
                        sku_code: "fl-01-1-10",
                        color_no: "1",
                        size: "10",
                        status: "active",
                        status_label: "有効",
                        not_sold: false,
                        quantities: [null, 3, 5],
                        amounts: [null, 16500, 27500],
                        channels: channels(
                            scope,
                            {
                                quantities: [null, 2, 5],
                                amounts: [null, 11000, 27500],
                                previous: { quantities: [null, null, 1], amounts: [null, null, 5500] },
                                last_year: { quantities: [null, 1, 4], amounts: [null, 5500, 22000] },
                            },
                            {
                                quantities: [null, 1, 0],
                                amounts: [null, 5500, 0],
                                previous: { quantities: [null, null, 1], amounts: [null, null, 5500] },
                                last_year: { quantities: [null, null, null], amounts: [null, null, null] },
                            },
                        ),
                    },
                    {
                        sku_id: 2,
                        sku_code: "fl-01-1-15",
                        color_no: "1",
                        size: "15",
                        status: "inactive",
                        status_label: "廃番",
                        not_sold: scope === "amazon",
                        quantities: [null, 1, 0],
                        amounts: [null, 5500, 0],
                        channels: channels(
                            scope,
                            {
                                quantities: [null, 1, 0],
                                amounts: [null, 5500, 0],
                                previous: { quantities: [null, null, 0], amounts: [null, null, 0] },
                                last_year: { quantities: [null, 1, 0], amounts: [null, 5500, 0] },
                            },
                            {
                                quantities: [null, 0, 0],
                                amounts: [null, 0, 0],
                                previous: { quantities: [null, null, 0], amounts: [null, null, 0] },
                                last_year: { quantities: [null, null, null], amounts: [null, null, null] },
                            },
                        ),
                    },
                ],
            },
            {
                item_no: "si-13",
                brand: "SHIORI",
                category: "老眼鏡",
                skus: [
                    {
                        sku_id: 3,
                        sku_code: "si-13-1-10",
                        color_no: "1",
                        size: "0",
                        status: "active",
                        status_label: "有効",
                        not_sold: false,
                        quantities: [null, 8, 2],
                        amounts: [null, 40000, 10000],
                        channels: channels(scope, { quantities: [null, 8, 2], amounts: [null, 40000, 10000] }, { quantities: [null, 0, 0], amounts: [null, 0, 0] }),
                    },
                ],
            },
        ],
        meta: {
            calculated_at: "2026-10-07T10:00:00+09:00",
            scope,
            scope_label: scope === "amazon" ? "Amazon" : "全体",
            selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 2 },
            unit: "month",
            from: "2026-08",
            to: "2026-10",
            periods: [
                { period: "2026-08", coverage: "none" },
                { period: "2026-09", coverage: "full" },
                { period: "2026-10", coverage: "partial" },
            ],
            channels: [
                { channel: "boss", label: "BOSS", sales_data_from: "2026-09-01", sales_data_to: "2026-10-06" },
                { channel: "amazon", label: "Amazon", sales_data_from: null, sales_data_to: null },
            ],
        },
    };
}
