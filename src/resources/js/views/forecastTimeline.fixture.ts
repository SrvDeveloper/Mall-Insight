import type { ForecastTimelineChannel, ForecastTimelineColumn, ForecastTimelineResponse, ForecastTimelineSku, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面（B-131）のテストで使う並び。月ごとは 2025-10〜2027-09（今月は 2026-10）、日ごとは 2026-10-10〜2026-10-16（今日は 10-13）。
 */

export function monthColumns(): ForecastTimelineColumn[] {
    return Array.from({ length: 24 }, (_, index) => {
        const month = 9 + index;
        const key = `${2025 + Math.floor(month / 12)}-${String((month % 12) + 1).padStart(2, "0")}`;
        return { key, kind: index < 12 ? "past" : index === 12 ? "current" : "future", days: 30 };
    });
}

export function dayColumns(): ForecastTimelineColumn[] {
    return ["2026-10-10", "2026-10-11", "2026-10-12", "2026-10-13", "2026-10-14", "2026-10-15", "2026-10-16"].map((key, index) => ({
        key,
        kind: index < 3 ? "past" : index === 3 ? "current" : "future",
        days: 1,
    }));
}

/** 過去は実績 actual・予測し直した数 replayed、これからは予測 forecast を、同じ値で並べる。 */
export function channel(
    columns: ForecastTimelineColumn[],
    values: { actual: number; replayed: number; forecast: number; base?: number },
    overrides: Partial<ForecastTimelineChannel> = {},
): ForecastTimelineChannel {
    return {
        base_average: values.base ?? values.forecast / 30,
        actual: columns.map((column) => (column.kind === "future" ? null : column.kind === "current" ? values.actual / 2 : values.actual)),
        actual_amount: columns.map((column) => (column.kind === "future" ? null : (column.kind === "current" ? values.actual / 2 : values.actual) * 1000)),
        stockout: columns.map((column) => (column.kind === "future" ? null : 0)),
        replayed: columns.map((column) => (column.kind === "past" ? values.replayed : null)),
        forecast: columns.map((column) => (column.kind === "past" ? null : values.forecast)),
        event: columns.map((column) => (column.kind === "past" ? null : 0)),
        ...overrides,
    };
}

export function sku(code: string, channels: ForecastTimelineSku["channels"], overrides: Partial<ForecastTimelineSku> = {}): ForecastTimelineSku {
    return {
        sku_id: Math.abs([...code].reduce((hash, char) => hash * 31 + char.charCodeAt(0), 7)) % 100000,
        sku_code: code,
        color_no: "1",
        size: "15",
        base_average: 1,
        is_partial: false,
        unpredictable_reason_label: null,
        unit_prices: { total: 1000, boss: 1000, amazon: 1000 },
        channels,
        ...overrides,
    };
}

export function timeline(unit: SalesUnit = "month"): ForecastTimelineResponse {
    const columns = unit === "day" ? dayColumns() : monthColumns();
    return {
        data: [
            {
                item_no: "si-13",
                brand: "FEELLIFE",
                category: "調光老眼鏡",
                skus: [
                    // 予測し直した数が実績の1.5倍（差異 +50%）
                    sku("si-13-1", { boss: channel(columns, { actual: 20, replayed: 30, forecast: 30 }) }),
                    sku("si-13-2", { boss: channel(columns, { actual: 0, replayed: 0, forecast: 0 }) }, { base_average: null, unpredictable_reason_label: "販売日数が足りない" }),
                ],
            },
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [
                    // BOSS は予測し直した数が実績より1割多く、Amazon は1割少ない
                    sku("fl-01-1", { boss: channel(columns, { actual: 100, replayed: 110, forecast: 120 }), amazon: channel(columns, { actual: 50, replayed: 45, forecast: 60 }) }),
                ],
            },
        ],
        meta: {
            calculated_at: "2026-10-13T06:00:00+09:00",
            channels: [
                { channel: "boss", label: "BOSS", sales_data_from: "2023-07-11", sales_data_to: "2026-10-12" },
                { channel: "amazon", label: "Amazon", sales_data_from: "2023-01-01", sales_data_to: "2026-10-12" },
            ],
            forecast_from: "2026-10-13",
            selection: { scope: "total", confirmed_at: "2026-10-01T10:00:00+09:00", item_count: 2, uses_candidates: false },
            window_weights: [
                { length: 30, weight: 0.5 },
                { length: 60, weight: 0.3 },
                { length: 90, weight: 0.2 },
            ],
            min_sales_days: 14,
            events: [
                {
                    id: 7,
                    event_id: 3,
                    name: "楽天スーパーセール",
                    starts_on: unit === "day" ? "2026-10-14" : "2026-12-04",
                    ends_on: unit === "day" ? "2026-10-15" : "2026-12-11",
                    channels: ["boss"],
                    status: "upcoming",
                },
            ],
            seasonal_item_nos: [],
            unit,
            offset: 0,
            offsets: [0, 3, 6],
            max_future_days: 90,
            columns,
        },
    };
}
