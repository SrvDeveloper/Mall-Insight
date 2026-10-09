import { apiClient } from "@/api/client";
import type { SalesActualsResponse, SalesUnit, StockActualsResponse, TrendScope } from "@/types/api";

/** 品番別売上・SKU別売上の期間（月ごとは YYYY-MM、日ごとは YYYY-MM-DD）。null なら初期値（直近12か月と今月、直近90日）。 */
export type SalesPeriod = { from: string; to: string } | null;

/**
 * 対象品番のSKUの、月ごと（直近12か月＋今月）か日ごと（直近90日）の販売数と金額。scope で全体・Amazon・BOSSを選ぶ。
 * itemNo を指定すると、その品番だけ（SKU別売上）。period で期間を指定できる（K-080）。
 */
export async function fetchSalesActuals(scope: TrendScope = "total", unit: SalesUnit = "month", itemNo: string | null = null, period: SalesPeriod = null): Promise<SalesActualsResponse> {
    const { data } = await apiClient.get<SalesActualsResponse>("/actuals/sales", { params: { scope, unit, item_no: itemNo ?? undefined, from: period?.from, to: period?.to } });
    return data;
}

/** 対象品番のSKUの、日ごとの在庫数（直近31日）。scope で全体（6区分の合計）・Amazon・BOSSを選ぶ。 */
export async function fetchStockActuals(scope: TrendScope = "total"): Promise<StockActualsResponse> {
    const { data } = await apiClient.get<StockActualsResponse>("/actuals/stock", { params: { scope } });
    return data;
}
