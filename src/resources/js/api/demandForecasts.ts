import { apiClient } from "@/api/client";
import type { DemandForecastResponse, ForecastTimelineResponse, SalesUnit, SkuForecast } from "@/types/api";

/** 1つの品番のSKUの需要予測（対象品番でなくてもよい）。入荷予定の自動割り振りで、SKUの比率に使う（K-054）。 */
export async function fetchItemForecast(itemNo: string): Promise<SkuForecast[]> {
    const { data } = await apiClient.get<DemandForecastResponse>("/demand-forecasts", { params: { item_no: itemNo } });
    return data.data[0]?.skus ?? [];
}

export interface ForecastTimelineQuery {
    unit: SalesUnit;
    /** 予測し直す起点（何か月前の1日か） */
    offset: number;
    /** 日ごとの期間（YYYY-MM-DD）。指定しなければ過去30日・これからの30日 */
    period?: { from: string; to: string } | null;
    /** SKU別の需要予測（その品番だけ、予測の根拠を添える） */
    itemNo?: string | null;
}

/** 需要予測の画面（B-131）の、過去の販売実績・予測し直した数と、これからの需要予測。 */
export async function fetchForecastTimeline(query: ForecastTimelineQuery): Promise<ForecastTimelineResponse> {
    const { data } = await apiClient.get<ForecastTimelineResponse>("/demand-forecasts/timeline", {
        params: {
            unit: query.unit,
            offset: query.offset,
            from: query.unit === "day" ? query.period?.from : undefined,
            to: query.unit === "day" ? query.period?.to : undefined,
            item_no: query.itemNo ?? undefined,
        },
    });
    return data;
}
