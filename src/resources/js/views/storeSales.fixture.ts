import type { StoreSales, StoreSalesResponse } from "@/types/api";

function store(mall: StoreSales["mall"], label: string, quantities: (number | null)[], unitPrice: number): StoreSales {
    const amounts = quantities.map((value) => (value === null ? null : value * unitPrice));
    return {
        mall,
        label,
        channel: mall === "amazon" ? "amazon" : "boss",
        coverage: quantities.map((value, index) => (value === null ? "none" : index === quantities.length - 1 ? "partial" : "full")),
        quantities,
        amounts,
        previous: { quantities: [null, ...quantities.slice(0, -1)], amounts: [null, ...amounts.slice(0, -1)] },
        last_year: { quantities: [null, null, null], amounts: [null, null, null] },
    };
}

/** 店舗売上のテストで使う販売実績（3か月）。Amazon は 2026年8月の販売実績を取り込んでいない。 */
export function storeSalesResponse(): StoreSalesResponse {
    return {
        data: [
            store("rakuten", "楽天市場", [6, 9, 5], 5000),
            store("yahoo", "Yahoo!ショッピング", [3, 5, 4], 5000),
            store("au_pay", "au PAY マーケット", [1, 1, 0], 5000),
            store("amazon", "Amazon", [null, 4, 2], 4000),
        ],
        meta: {
            calculated_at: "2026-10-07T10:00:00+09:00",
            selection: null,
            unit: "month",
            from: "2026-08",
            to: "2026-10",
            products: "all",
            periods: [
                { period: "2026-08", coverage: "full" },
                { period: "2026-09", coverage: "full" },
                { period: "2026-10", coverage: "partial" },
            ],
            channels: [
                { channel: "boss", label: "BOSS", sales_data_from: "2025-09-01", sales_data_to: "2026-10-06" },
                { channel: "amazon", label: "Amazon", sales_data_from: "2026-09-01", sales_data_to: "2026-10-06" },
            ],
        },
    };
}
