import { apiClient } from "@/api/client";
import type { SalesActualsResponse, SalesUnit, StockActualsResponse, StoreRankingResponse, StoreSales, StoreSalesProducts, StoreSalesResponse, TrendScope } from "@/types/api";

/** 品番別売上・SKU別売上の期間（月ごとは YYYY-MM、日ごとは YYYY-MM-DD）。null なら初期値（直近12か月と今月、直近30日）。 */
export type SalesPeriod = { from: string; to: string } | null;

/**
 * 対象品番のSKUの、月ごと（直近12か月＋今月）か日ごと（直近30日）の販売数と金額。scope で全体・Amazon・BOSSを選ぶ。
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

/** 店舗ごとの、月ごと（直近12か月＋今月）か日ごと（直近30日）の販売数と金額。products で全商品か対象品番だけかを選ぶ（K-088）。period で期間を指定できる（K-080）。 */
export async function fetchStoreSales(unit: SalesUnit = "month", products: StoreSalesProducts = "all", period: SalesPeriod = null): Promise<StoreSalesResponse> {
    const { data } = await apiClient.get<StoreSalesResponse>("/actuals/stores", { params: { unit, products, from: period?.from, to: period?.to } });
    return data;
}

/** 店舗売上の月・日の欄の、売上上位の品番とSKU（K-091）。mall が null なら全店舗。order は金額か販売数の多い順。 */
export async function fetchStoreRanking(unit: SalesUnit, period: string, mall: StoreSales["mall"] | null, products: StoreSalesProducts, order: "amount" | "quantity"): Promise<StoreRankingResponse> {
    const { data } = await apiClient.get<StoreRankingResponse>("/actuals/stores/ranking", { params: { unit, period, mall: mall ?? undefined, products, order } });
    return data;
}
