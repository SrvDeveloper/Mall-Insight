import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import DemandForecastView from "@/views/DemandForecastView.vue";
import { fetchDemandForecasts } from "@/api/demandForecasts";
import type { AverageWindow, ChannelForecast, DemandForecastResponse, MonthlyDemand, SkuForecast } from "@/types/api";

vi.mock("@/api/demandForecasts", () => ({ fetchDemandForecasts: vi.fn() }));

const months = Array.from({ length: 12 }, (_, index) => {
    const date = new Date(2026, 9 + index, 1);
    return { month: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`, days: index === 0 ? 25 : 30 };
});

function monthly(baseAverage: number): MonthlyDemand[] {
    return months.map((month) => ({ ...month, quantity: baseAverage * month.days }));
}

const windows: AverageWindow[] = [
    {
        length: 30,
        offset: 0,
        label: "直近30日",
        from: "2026-09-07",
        to: "2026-10-06",
        sales_days: 30,
        stockout_days: 0,
        sales_quantity: 60,
        average: 2,
        base_weight: 0.5,
        weight: 0.5,
        is_excluded: false,
    },
    {
        length: 60,
        offset: 30,
        label: "31〜90日前",
        from: "2026-07-09",
        to: "2026-09-06",
        sales_days: 57,
        stockout_days: 3,
        sales_quantity: 90,
        average: 1.5,
        base_weight: 0.3,
        weight: 0.3,
        is_excluded: false,
    },
    {
        length: 90,
        offset: 90,
        label: "91〜180日前",
        from: "2026-04-10",
        to: "2026-07-08",
        sales_days: 90,
        stockout_days: 0,
        sales_quantity: 135,
        average: 1.5,
        base_weight: 0.2,
        weight: 0.2,
        is_excluded: false,
    },
];

function channel(channelName: "boss" | "amazon", baseAverage: number | null, reason: string | null = null): ChannelForecast {
    return {
        channel: channelName,
        channel_label: channelName === "boss" ? "BOSS" : "Amazon",
        base_average: baseAverage,
        unpredictable_reason: reason ? "no_sales" : null,
        unpredictable_reason_label: reason,
        windows,
        monthly: baseAverage === null ? null : monthly(baseAverage),
    };
}

function sku(sku_id: number, sku_code: string, channels: ChannelForecast[]): SkuForecast {
    const predictable = channels.filter((candidate) => candidate.base_average !== null);
    const baseAverage = predictable.length ? predictable.reduce((total, candidate) => total + candidate.base_average!, 0) : null;
    const unpredictable = channels.filter((candidate) => candidate.base_average === null);
    return {
        sku_id,
        sku_code,
        status: "active",
        status_label: "有効",
        base_average: baseAverage,
        monthly: baseAverage === null ? null : monthly(baseAverage),
        is_partial: predictable.length > 0 && unpredictable.length > 0,
        unpredictable_reason_label: unpredictable.length ? unpredictable.map((candidate) => `${candidate.channel_label}：${candidate.unpredictable_reason_label}`).join("／") : null,
        channels,
    };
}

const NO_SALES = "直近180日に販売実績がありません";

function response(overrides: Partial<DemandForecastResponse["meta"]> = {}, data: DemandForecastResponse["data"] | null = null): DemandForecastResponse {
    return {
        data: data ?? [
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [sku(1, "fl-01-1-10", [channel("boss", 1.75)]), sku(2, "fl-01-1-15", [channel("boss", 0.5)]), sku(3, "fl-01-1-20", [channel("boss", null, NO_SALES)])],
            },
        ],
        meta: {
            calculated_at: "2026-10-07T10:00:00+09:00",
            channels: [
                { channel: "boss", label: "BOSS", sales_data_from: "2025-10-07", sales_data_to: "2026-10-06" },
                { channel: "amazon", label: "Amazon", sales_data_from: null, sales_data_to: null },
            ],
            forecast_from: "2026-10-07",
            selection: { confirmed_at: "2026-10-07T09:00:00+09:00", item_count: 1 },
            window_weights: [
                { length: 30, weight: 0.5 },
                { length: 60, weight: 0.3 },
                { length: 90, weight: 0.2 },
            ],
            min_sales_days: 14,
            ...overrides,
        },
    };
}

const bothChannels: DemandForecastResponse["meta"]["channels"] = [
    { channel: "boss", label: "BOSS", sales_data_from: "2025-10-07", sales_data_to: "2026-10-06" },
    { channel: "amazon", label: "Amazon", sales_data_from: "2025-10-07", sales_data_to: "2026-10-06" },
];

async function mountView() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: DemandForecastView }] });
    const wrapper = mount(DemandForecastView, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper;
}

function viewButton(wrapper: Awaited<ReturnType<typeof mountView>>, label: string) {
    return wrapper.findAll('[aria-label="表示するチャネル"] button').find((button) => button.text() === label)!;
}

describe("DemandForecastView", () => {
    beforeEach(() => {
        vi.mocked(fetchDemandForecasts).mockReset();
    });

    it("shows the calculation time, the sales data range of each channel and the target items", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());

        const wrapper = await mountView();

        const basis = wrapper.find('[data-testid="forecast-basis"]').text();
        expect(basis).toContain("2026/10/07 10:00");
        expect(basis).toContain("1品番（2026/10/07 09:00 確定）");
        expect(wrapper.find('[data-testid="sales-range-boss"]').text()).toContain("2025/10/07 〜 2026/10/06");
        expect(wrapper.find('[data-testid="sales-range-amazon"]').text()).toContain("まだ取り込まれていません");
        expect(wrapper.find('[data-testid="stale-sales"]').exists()).toBe(false);
        expect(viewButton(wrapper, "Amazon").attributes("disabled")).toBeDefined();
    });

    it("shows monthly forecasts per SKU with item totals that leave out unpredictable SKUs", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());

        const wrapper = await mountView();

        const headers = wrapper.findAll("thead th").map((th) => th.text());
        expect(headers[2]).toBe("26/10 25日分");
        expect(headers[4]).toBe("26/12");
        const skuRows = wrapper.findAll('[data-testid="sku-row"]');
        const cells = skuRows[0]!.findAll("td").map((td) => td.text());
        expect(cells[1]).toBe("1.75");
        expect(cells[2]).toBe("44");
        expect(cells[3]).toBe("53");
        const itemCells = wrapper
            .find('[data-testid="item-row"]')
            .findAll("td")
            .map((td) => td.text());
        expect(itemCells[0]).toBe("予測不能を除く");
        expect(itemCells[1]).toBe("56");
        expect(skuRows[2]!.text()).toContain("予測不能");
        expect(skuRows[2]!.find('[data-testid="unpredictable-reason"]').text()).toBe(`BOSS：${NO_SALES}`);
        expect(wrapper.find('[data-testid="forecast-summary"]').text()).toContain("予測不能 1件");
    });

    it("switches between the total and each channel and marks partly unpredictable SKUs", async () => {
        const data = [
            {
                item_no: "fl-01",
                brand: "FEELLIFE",
                category: "老眼鏡",
                skus: [sku(1, "fl-01-1-10", [channel("boss", 1), channel("amazon", 2)]), sku(2, "fl-01-1-15", [channel("boss", 0.5), channel("amazon", null, NO_SALES)])],
            },
        ];
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ channels: bothChannels }, data));
        const wrapper = await mountView();

        const baseAverages = () => wrapper.findAll('[data-testid="base-average"]').map((cell) => cell.text());
        expect(baseAverages()).toEqual(["3.00", "0.50"]);
        expect(wrapper.findAll('[data-testid="sku-row"]')[1]!.find('[data-testid="partial"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="forecast-summary"]').text()).toContain("一部予測不能 1件");

        await viewButton(wrapper, "Amazon").trigger("click");

        expect(baseAverages()).toEqual(["2.00", "予測不能"]);
        expect(wrapper.findAll('[data-testid="sku-row"]')[1]!.find('[data-testid="unpredictable-reason"]').text()).toBe(NO_SALES);
        expect(wrapper.find('[data-testid="partial"]').exists()).toBe(false);

        await viewButton(wrapper, "BOSS").trigger("click");

        expect(baseAverages()).toEqual(["1.00", "0.50"]);
    });

    it("draws only the rows around the visible area and swaps them while scrolling", async () => {
        const many = [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", skus: Array.from({ length: 300 }, (_, index) => sku(index + 1, `fl-01-${index + 1}`, [channel("boss", 1)])) }];
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({}, many));
        const wrapper = await mountView();

        const rendered = () => wrapper.findAll('[data-testid="sku-row"]').map((row) => row.find("td").text());
        expect(rendered().length).toBeLessThan(80);
        expect(rendered()[0]).toBe("fl-01-1");

        const box = wrapper.find('[data-testid="forecast-scroll"]');
        (box.element as HTMLElement).scrollTop = 37 * 200;
        await box.trigger("scroll");
        await new Promise((resolve) => setTimeout(resolve, 50));
        await flushPromises();

        expect(rendered()).toContain("fl-01-200");
        expect(rendered()).not.toContain("fl-01-1");
        expect(rendered().length).toBeLessThan(80);
    });

    it("shows a SKU that is not sold on a channel as not sold instead of unpredictable", async () => {
        const data = [
            { item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", skus: [sku(1, "fl-01-1-10", [channel("boss", 1), channel("amazon", 2)]), sku(2, "fl-01-1-15", [channel("boss", 0.5)])] },
        ];
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ channels: bothChannels }, data));
        const wrapper = await mountView();

        await viewButton(wrapper, "Amazon").trigger("click");

        const row = wrapper.findAll('[data-testid="sku-row"]')[1]!;
        expect(row.find('[data-testid="base-average"]').text()).toBe("—");
        expect(row.find('[data-testid="unpredictable-reason"]').text()).toBe("Amazonでは販売していません");
        expect(wrapper.find('[data-testid="forecast-summary"]').text()).not.toContain("予測不能");
        expect(wrapper.find('[data-testid="item-row"]').text()).not.toContain("予測不能を除く");
    });

    it("shows each channel's windows and stockout days in a tooltip when hovering over the base average", async () => {
        const data = [{ item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", skus: [sku(1, "fl-01-1-10", [channel("boss", 1), channel("amazon", 2)])] }];
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ channels: bothChannels }, data));
        const wrapper = await mountView();
        vi.useFakeTimers();

        try {
            const average = wrapper.find('[data-testid="base-average"]');
            await average.trigger("mouseenter");
            expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
            await vi.advanceTimersByTimeAsync(200);

            const tooltip = wrapper.find('[role="tooltip"]');
            expect(tooltip.text()).toContain("fl-01-1-10 の基準平均日販 3.00");
            expect(tooltip.find('[data-testid="tooltip-boss"]').text()).toContain("BOSS 1.00");
            expect(tooltip.find('[data-testid="tooltip-amazon"]').text()).toContain("Amazon 2.00");
            const row = tooltip.find('[data-testid="tooltip-boss"]').findAll("tbody tr")[1]!;
            expect(row.text()).toContain("31〜90日前");
            expect(row.text()).toContain("2026/07/09 〜 2026/09/06");
            expect(row.findAll("td")[4]!.text()).toBe("3");
            expect(average.attributes("aria-describedby")).toBe("average-tooltip");

            await average.trigger("mouseleave");
            expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);

            await viewButton(wrapper, "Amazon").trigger("click");
            await wrapper.find('[data-testid="base-average"]').trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(200);
            expect(wrapper.find('[data-testid="tooltip-boss"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="tooltip-amazon"]').exists()).toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it("shows the reason in the tooltip for an unpredictable SKU when focused", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());
        const wrapper = await mountView();

        const average = wrapper.findAll('[data-testid="base-average"]')[2]!;
        expect(average.text()).toBe("予測不能");
        await average.trigger("focus");
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await flushPromises();

        expect(wrapper.find('[role="tooltip"]').text()).toContain(NO_SALES);
    });

    it("can show only SKUs that are wholly or partly unpredictable", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response());
        const wrapper = await mountView();

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(wrapper.findAll('[data-testid="sku-row"]').map((row) => row.text())).toEqual([expect.stringContaining("fl-01-1-20")]);
    });

    it("warns when a channel's latest sales data is a week old", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(
            response({
                channels: [
                    { channel: "boss", label: "BOSS", sales_data_from: "2025-10-07", sales_data_to: "2026-09-30" },
                    { channel: "amazon", label: "Amazon", sales_data_from: null, sales_data_to: null },
                ],
            }),
        );

        const wrapper = await mountView();

        expect(wrapper.find('[data-testid="sales-range-boss"] [data-testid="stale-sales"]').text()).toContain("最新日から7日たっています。");
    });

    it("asks to confirm target items first", async () => {
        vi.mocked(fetchDemandForecasts).mockResolvedValue(response({ selection: null }, []));

        const wrapper = await mountView();

        expect(wrapper.text()).toContain("対象品番がまだ確定されていません。");
        expect(wrapper.find("a").attributes("href")).toBe("/target-items");
    });
});
