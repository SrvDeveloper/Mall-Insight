import { apiClient } from "@/api/client";
import type { BacktestHorizon, ForecastBacktest, ForecastBacktestSummaryResponse } from "@/types/api";

/** 予測精度を測った記録（新しい順、最新20件、B-130）。 */
export async function fetchForecastBacktests(): Promise<ForecastBacktest[]> {
    const { data } = await apiClient.get<{ data: ForecastBacktest[] }>("/forecast-backtests");
    return data.data;
}

/** 測った結果を、何か月先とチャネル（全体・BOSS・Amazon）で絞って集計したもの。 */
export async function fetchForecastBacktest(id: number, horizon: BacktestHorizon, channel: "total" | "boss" | "amazon"): Promise<ForecastBacktestSummaryResponse> {
    const { data } = await apiClient.get<ForecastBacktestSummaryResponse>(`/forecast-backtests/${id}`, { params: { horizon, channel } });
    return data;
}

/** 今の販売実績で測り直す（数秒かかる）。 */
export async function runForecastBacktest(): Promise<ForecastBacktest> {
    const { data } = await apiClient.post<{ data: ForecastBacktest }>("/forecast-backtests");
    return data.data;
}
