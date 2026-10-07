import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import SalesImportView from "@/views/SalesImportView.vue";
import { fetchSalesImport, fetchSalesImports, uploadSalesImport } from "@/api/salesImports";
import { ApiError } from "@/api/client";
import type { Paginated, SalesImport } from "@/types/api";

vi.mock("@/api/salesImports", () => ({ uploadSalesImport: vi.fn(), fetchSalesImports: vi.fn(), fetchSalesImport: vi.fn() }));

function salesImport(overrides: Partial<SalesImport>): SalesImport {
    return {
        id: 1,
        source: "boss",
        source_label: "BOSS",
        file_name: "BOSS受注実績.csv",
        status: "succeeded",
        status_label: "成功",
        started_at: "2026-10-06T18:00:00+09:00",
        finished_at: "2026-10-06T18:00:01+09:00",
        row_count: 526,
        created_line_count: 520,
        updated_line_count: 0,
        skipped_line_count: 0,
        error_row_count: 6,
        created_sku_count: 127,
        warning_count: 1,
        sales_date_from: "2026-08-18",
        sales_date_to: "2026-08-25",
        error_message: null,
        issues: [],
        ...overrides,
    };
}

function page(data: SalesImport[]): Paginated<SalesImport> {
    return { data, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, from: 1, last_page: 1, per_page: 20, to: data.length, total: data.length } };
}

async function mountView() {
    const wrapper = mount(SalesImportView);
    await flushPromises();
    return wrapper;
}

async function chooseFileAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>, names: string[] = ["BOSS受注実績.csv"]) {
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, "files", { value: names.map((name) => new File(["x"], name)), configurable: true });
    await input.trigger("change");
    await wrapper.find("form").trigger("submit");
    await flushPromises();
}

describe("SalesImportView", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(uploadSalesImport).mockReset();
        vi.mocked(fetchSalesImport).mockReset();
        vi.mocked(fetchSalesImports).mockReset().mockResolvedValue(page([]));
    });

    it("keeps the import button disabled until a file is chosen", async () => {
        const wrapper = await mountView();

        expect(wrapper.find('button[type="submit"]').attributes("disabled")).toBeDefined();
        expect(wrapper.text()).toContain("まだ取り込んでいません。");
    });

    it("shows counts and the issues sorted by row after uploading", async () => {
        vi.mocked(uploadSalesImport).mockResolvedValue(
            salesImport({
                issues: [
                    { id: 2, level: "warning", level_label: "警告", row_number: 40, source_order_id: "1002", sku_code: "afc20", message: "商品単価が0円です。" },
                    { id: 1, level: "error", level_label: "エラー", row_number: 3, source_order_id: "1001", sku_code: null, message: "ショップID「99999」に対応するモールがありません。" },
                ],
            }),
        );
        const wrapper = await mountView();

        await chooseFileAndSubmit(wrapper);

        const result = wrapper.find('[aria-label="取込の結果"]');
        expect(result.text()).toContain("エラーあり");
        expect(result.text()).toContain("新しく登録520");
        expect(result.text()).toContain("CrossWalkerに無いSKU127");
        expect(result.text()).toContain("販売日 2026/08/18 〜 2026/08/25 の販売実績を登録しました。");
        expect(result.text()).toContain("エラー 1件・警告 1件");
        const rows = result.findAll("tbody tr");
        expect(rows[0].text()).toContain("ショップID「99999」");
        expect(rows[1].text()).toContain("商品単価が0円です。");
        expect(fetchSalesImports).toHaveBeenCalledTimes(2);
    });

    it("shows why the whole file could not be imported", async () => {
        vi.mocked(uploadSalesImport).mockResolvedValue(salesImport({ status: "failed", row_count: null, error_message: "ファイルがUTF-8で保存されています。" }));
        const wrapper = await mountView();

        await chooseFileAndSubmit(wrapper);

        expect(wrapper.text()).toContain("取り込めませんでした");
        expect(wrapper.text()).toContain("ファイルがUTF-8で保存されています。");
    });

    it("shows the validation message when the server rejects the file", async () => {
        vi.mocked(uploadSalesImport).mockRejectedValue(
            new ApiError("ファイルには次の拡張子のファイルを指定してください：csv", 422, { file: ["ファイルには次の拡張子のファイルを指定してください：csv"] }),
        );
        const wrapper = await mountView();

        await chooseFileAndSubmit(wrapper);

        expect(wrapper.text()).toContain("ファイルには次の拡張子のファイルを指定してください：csv");
    });

    it("opens a past import from the history", async () => {
        vi.mocked(fetchSalesImports).mockResolvedValue(page([salesImport({ id: 7, file_name: "8月分.csv" })]));
        vi.mocked(fetchSalesImport).mockResolvedValue(salesImport({ id: 7, file_name: "8月分.csv", error_row_count: 0, issues: [] }));
        const wrapper = await mountView();

        await wrapper
            .findAll("button")
            .find((button) => button.text() === "詳細")!
            .trigger("click");
        await flushPromises();

        expect(fetchSalesImport).toHaveBeenCalledWith(7);
        expect(wrapper.find('[aria-label="取込の結果"]').text()).toContain("8月分.csv");
        expect(wrapper.text()).toContain("エラー・警告はありません。");
    });

    it("imports several files one by one in file name order and lists each result", async () => {
        vi.mocked(uploadSalesImport).mockImplementation(async (file: File) => {
            if (file.name.includes("2025年12月")) {
                throw new ApiError("入力内容を確認してください。", 422, { file: ["ファイルには30720 KB以下のファイルを指定してください。"] });
            }
            return salesImport({ id: file.name.length, source: "amazon", source_label: "Amazon", file_name: file.name, error_row_count: file.name.includes("10月") ? 2 : 0 });
        });
        const wrapper = await mountView();

        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, "files", { value: ["全注文_2025年12月.txt", "全注文_2025年10月.txt", "全注文_2025年11月.txt"].map((name) => new File(["x"], name)), configurable: true });
        await input.trigger("change");
        expect(wrapper.find('button[type="submit"]').text()).toBe("3ファイルを取り込む");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(vi.mocked(uploadSalesImport).mock.calls.map(([file]) => file.name)).toEqual(["全注文_2025年10月.txt", "全注文_2025年11月.txt", "全注文_2025年12月.txt"]);
        const rows = wrapper.findAll('[data-testid="batch-row"]').map((row) => row.text());
        expect(rows[0]).toContain("Amazon");
        expect(rows[0]).toContain("エラーあり");
        expect(rows[1]).toContain("取り込みました");
        expect(rows[2]).toContain("取り込めませんでした");
        expect(rows[2]).toContain("ファイルには30720 KB以下のファイルを指定してください。");
        expect(fetchSalesImports).toHaveBeenCalledTimes(2);
    });
});
