import { apiClient } from "@/api/client";
import type { SalesActualsResponse, StockActualsResponse, TrendScope } from "@/types/api";

/** 対象品番のSKUの、月ごとの販売数（直近12か月＋今月）。scope で全体・Amazon・BOSSを選ぶ。 */
export async function fetchSalesActuals(scope: TrendScope = "total"): Promise<SalesActualsResponse> {
    const { data } = await apiClient.get<SalesActualsResponse>("/actuals/sales", { params: { scope } });
    return data;
}

/** 対象品番のSKUの、日ごとの在庫数（直近31日）。scope で全体（6区分の合計）・Amazon・BOSSを選ぶ。 */
export async function fetchStockActuals(scope: TrendScope = "total"): Promise<StockActualsResponse> {
    const { data } = await apiClient.get<StockActualsResponse>("/actuals/stock", { params: { scope } });
    return data;
}
