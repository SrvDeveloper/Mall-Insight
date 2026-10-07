import { apiClient } from "@/api/client";
import type { Paginated, SalesImport } from "@/types/api";

/** BOSS受注実績のCSVを取り込む。ファイル全体を取り込めなかった場合も、失敗の記録が返る。 */
export async function uploadSalesImport(file: File): Promise<SalesImport> {
    const form = new FormData();
    form.append("file", file);
    const { data } = await apiClient.post<{ data: SalesImport }>("/sales-imports", form);
    return data.data;
}

export async function fetchSalesImports(page: number): Promise<Paginated<SalesImport>> {
    const { data } = await apiClient.get<Paginated<SalesImport>>("/sales-imports", { params: { page } });
    return data;
}

export async function fetchSalesImport(id: number): Promise<SalesImport> {
    const { data } = await apiClient.get<{ data: SalesImport }>(`/sales-imports/${id}`);
    return data.data;
}
