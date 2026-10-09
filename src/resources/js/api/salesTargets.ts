import { apiClient } from "@/api/client";
import type { CarryOverCandidate, MonthlySalesRatio, SalesTarget, SalesTargetPayload, SalesTargetResponse } from "@/types/api";

/** 年度の年間販売目標と、月・SKUへの割り振り（B-110）。呼ぶたびに最新の目標・比率・販売実績から計算される。 */
export async function fetchSalesTargets(fiscalYear?: number): Promise<SalesTargetResponse> {
    const { data } = await apiClient.get<SalesTargetResponse>("/sales-targets", { params: { fiscal_year: fiscalYear } });
    return data;
}

/** 品番の年間販売目標を登録・変更する。新しい版として記録され、前の版は残る。 */
export async function saveSalesTarget(payload: SalesTargetPayload): Promise<SalesTarget> {
    const { data } = await apiClient.post<{ data: SalesTarget }>("/sales-targets", payload);
    return data.data;
}

/** 品番の年間販売目標の変更履歴（新しい順）。 */
export async function fetchSalesTargetHistory(fiscalYear: number, itemNo: string): Promise<SalesTarget[]> {
    const { data } = await apiClient.get<{ data: SalesTarget[] }>("/sales-targets/history", { params: { fiscal_year: fiscalYear, item_no: itemNo } });
    return data.data;
}

/** 区分の月別販売比率を変更する。12か月の合計は10000（100%）でなければならない（K-106）。 */
export async function saveMonthlySalesRatio(ratioCategoryId: number, ratios: number[]): Promise<MonthlySalesRatio> {
    const { data } = await apiClient.post<{ data: MonthlySalesRatio }>("/monthly-sales-ratios", { ratio_category_id: ratioCategoryId, ratios });
    return data.data;
}

/** 品番に使う月別販売比率の区分を変更する。 */
export async function saveItemRatioCategory(itemNo: string, ratioCategoryId: number): Promise<void> {
    await apiClient.put(`/item-ratio-categories/${encodeURIComponent(itemNo)}`, { ratio_category_id: ratioCategoryId });
}

/** 月別販売比率の区分を足す（K-061）。比率は足した後に入れる。 */
export async function createRatioCategory(name: string): Promise<void> {
    await apiClient.post("/ratio-categories", { name });
}

/** 月別販売比率の区分の名前を変える（K-061）。 */
export async function renameRatioCategory(id: number, name: string): Promise<void> {
    await apiClient.patch(`/ratio-categories/${id}`, { name });
}

/** 前年度から引き継げる目標（前年度に目標のある品番）。 */
export async function fetchCarryOverCandidates(fiscalYear: number): Promise<CarryOverCandidate[]> {
    const { data } = await apiClient.get<{ data: CarryOverCandidate[] }>("/sales-targets/carry-over", { params: { fiscal_year: fiscalYear } });
    return data.data;
}

/** 選んだ品番の目標を前年度から引き継ぐ。年間の数は必ず写し、月の手直しとSKUの上書きは選んだときだけ写す。 */
export async function carryOverSalesTargets(fiscalYear: number, itemNos: string[], includeMonthly: boolean, includeSkus: boolean): Promise<number> {
    const { data } = await apiClient.post<{ data: { count: number } }>("/sales-targets/carry-over", {
        fiscal_year: fiscalYear,
        item_nos: itemNos,
        include_monthly: includeMonthly,
        include_skus: includeSkus,
    });
    return data.data.count;
}
