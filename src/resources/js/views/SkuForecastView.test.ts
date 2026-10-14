import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import SkuForecastView from "@/views/SkuForecastView.vue";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import { cancelForecastAdjustment, fetchForecastAdjustments, saveForecastAdjustment } from "@/api/forecastAdjustments";
import { ApiError } from "@/api/client";
import { timeline } from "@/views/forecastTimeline.fixture";
import type { ForecastAdjustment, ForecastTimelineResponse, SalesUnit, SkuForecast } from "@/types/api";

vi.mock("@/api/demandForecasts", () => ({ fetchForecastTimeline: vi.fn() }));
vi.mock("@/api/forecastAdjustments", () => ({ fetchForecastAdjustments: vi.fn(), saveForecastAdjustment: vi.fn(), cancelForecastAdjustment: vi.fn() }));

function adjustment(overrides: Partial<ForecastAdjustment> = {}): ForecastAdjustment {
    return {
        id: 1,
        item_no: "fl-01",
        channel: "amazon",
        channel_label: "Amazon",
        starts_on: "2026-11",
        ends_on: "2027-01",
        rate_percent: 120,
        reason: "Amazon の広告を強める",
        status: "scheduled",
        created_at: "2026-10-13T10:00:00+09:00",
        created_by: "担当者",
        cancelled_at: null,
        cancelled_by: null,
        ...overrides,
    };
}

function detail(): Omit<SkuForecast, "unit_prices"> {
    const monthly = ["2026-10", "2026-11", "2026-12"].map((month, index) => ({
        month,
        days: 30,
        quantity: index === 2 ? 140 : 120,
        event_quantity: index === 2 ? 20 : 0,
        seasonal_factor: null,
        adjustment_rate: null,
        adjustment_quantity: 0,
        events:
            index === 2
                ? [{ period_id: 7, event_id: 3, name: "楽天スーパーセール", channel: "boss" as const, days: 4, multiplier: 2, is_candidate: true, quantity: 20 }]
                : index === 0
                  ? [{ period_id: 7, event_id: 3, name: "楽天スーパーセール", channel: "boss" as const, days: 2, multiplier: 1.5, is_candidate: true, quantity: 0 }]
                  : [],
    }));
    const window = {
        length: 30,
        offset: 0,
        label: "直近30日",
        from: "2026-09-12",
        to: "2026-10-11",
        sales_days: 27,
        stockout_days: 3,
        event_days: 0,
        seasonal_factor: null,
        sales_quantity: 108,
        average: 4,
        base_weight: 0.5,
        weight: 0.5,
        is_excluded: false,
    };
    const noSales = { base_average: null, unpredictable_reason: "no_sales" as const, unpredictable_reason_label: "販売実績がありません", windows: [{ ...window, sales_quantity: 0, average: 0 }] };
    return {
        sku_id: 1,
        sku_code: "fl-01-1",
        status: null,
        status_label: null,
        base_average: 6,
        monthly,
        is_partial: true,
        unpredictable_reason_label: "Amazon：販売日数が足りない",
        channels: [
            {
                channel: "boss",
                channel_label: "BOSS",
                base_average: 4,
                unpredictable_reason: null,
                unpredictable_reason_label: null,
                // BOSS は楽天市場だけで売れている
                windows: [],
                malls: [
                    { mall: "rakuten", mall_label: "楽天市場", base_average: 4, unpredictable_reason: null, unpredictable_reason_label: null, windows: [window] },
                    { mall: "yahoo", mall_label: "Yahoo!ショッピング", ...noSales },
                    { mall: "au_pay", mall_label: "au PAY マーケット", ...noSales },
                ],
                monthly,
            },
            {
                channel: "amazon",
                channel_label: "Amazon",
                base_average: null,
                unpredictable_reason: "insufficient_days",
                unpredictable_reason_label: "販売日数が足りない",
                windows: [],
                malls: [{ mall: "amazon", mall_label: "Amazon", base_average: null, unpredictable_reason: "insufficient_days", unpredictable_reason_label: "販売日数が足りない", windows: [] }],
                monthly: null,
            },
        ],
    };
}

function itemTimeline(unit: SalesUnit = "month"): ForecastTimelineResponse {
    const response = timeline(unit);
    response.data = [response.data[1]!];
    response.data[0]!.skus[0]!.detail = detail();
    return response;
}

async function mountView(path = "/forecasts/fl-01") {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: "/forecasts", name: "forecasts", component: { template: "<div />" } },
            { path: "/forecasts/:itemNo", name: "sku-forecasts", component: SkuForecastView },
        ],
    });
    await router.push(path);
    const wrapper = mount(SkuForecastView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router };
}

describe("SkuForecastView", () => {
    beforeEach(() => {
        vi.mocked(fetchForecastTimeline).mockReset().mockResolvedValue(itemTimeline());
        vi.mocked(fetchForecastAdjustments)
            .mockReset()
            .mockResolvedValue({ data: [], meta: { min_percent: 0, max_percent: 500 } });
        vi.mocked(saveForecastAdjustment).mockReset();
        vi.mocked(cancelForecastAdjustment).mockReset();
    });

    it("registers an adjustment of the item and reloads the forecast", async () => {
        const { wrapper } = await mountView();
        expect(fetchForecastAdjustments).toHaveBeenCalledWith("fl-01");
        expect(wrapper.find('[data-testid="adjustment-empty"]').text()).toBe("補正はありません。");

        await wrapper.find('[data-testid="adjustment-add"]').trigger("click");
        // 月は予測の12か月（今月から）
        expect(wrapper.findAll('[data-testid="adjustment-starts-on"] option').map((option) => option.attributes("value"))).toHaveLength(12);
        await wrapper.find('[data-testid="adjustment-channel-amazon"]').trigger("click");
        await wrapper.find('[data-testid="adjustment-starts-on"]').setValue("2026-11");
        await wrapper.find('[data-testid="adjustment-ends-on"]').setValue("2027-01");
        await wrapper.find('[data-testid="adjustment-rate"]').setValue(120);
        expect(wrapper.find('[data-testid="adjustment-rate-hint"]').text()).toBe("予測を20%増やします（予測 × 1.2）。");
        await wrapper.find('[data-testid="adjustment-reason"]').setValue("Amazon の広告を強める");

        // 入力エラーは欄の下に出す
        vi.mocked(saveForecastAdjustment).mockRejectedValueOnce(new ApiError("入力内容を確認してください。", 422, { starts_on: ["このチャネルの別の補正（2026/12〜、150%）と月が重なっています。"] }));
        await wrapper.find('[data-testid="adjustment-form"]').trigger("submit");
        await flushPromises();
        expect(wrapper.find('[data-testid="adjustment-errors"]').text()).toContain("月が重なっています");

        vi.mocked(saveForecastAdjustment).mockResolvedValueOnce(adjustment());
        vi.mocked(fetchForecastAdjustments).mockResolvedValue({ data: [adjustment()], meta: { min_percent: 0, max_percent: 500 } });
        const loads = vi.mocked(fetchForecastTimeline).mock.calls.length;
        await wrapper.find('[data-testid="adjustment-form"]').trigger("submit");
        await flushPromises();
        expect(saveForecastAdjustment).toHaveBeenLastCalledWith({
            item_no: "fl-01",
            channel: "amazon",
            starts_on: "2026-11",
            ends_on: "2027-01",
            rate_percent: 120,
            reason: "Amazon の広告を強める",
            replaces: null,
        });
        expect(fetchForecastTimeline).toHaveBeenCalledTimes(loads + 1);
        expect(wrapper.find('[data-testid="adjustment-form"]').exists()).toBe(false);
        const row = wrapper.find('[data-testid="adjustment-row"]');
        expect(row.text()).toContain("Amazon");
        expect(row.text()).toContain("2026/11〜2027/1");
        expect(row.find('[data-testid="adjustment-status"]').text()).toBe("これから");
        expect(row.find('[data-testid="adjustment-rate-cell"]').text()).toBe("120%");
        wrapper.unmount();
    });

    it("changes an adjustment by replacing it, cancels one and shows the past adjustments", async () => {
        vi.mocked(fetchForecastAdjustments).mockResolvedValue({
            data: [
                adjustment({ status: "active", starts_on: "2026-10", ends_on: null }),
                adjustment({ id: 2, channel: "boss", channel_label: "BOSS", status: "cancelled", cancelled_at: "2026-10-12T10:00:00+09:00", cancelled_by: "担当者" }),
            ],
            meta: { min_percent: 0, max_percent: 500 },
        });
        const { wrapper } = await mountView();
        expect(wrapper.findAll('[data-testid="adjustment-row"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="adjustment-row"]').text()).toContain("2026/10〜（取り消すまで）");

        // 変えるときは、同じチャネルで前の補正を置き換える
        await wrapper.find('[data-testid="adjustment-change"]').trigger("click");
        expect((wrapper.find('[data-testid="adjustment-rate"]').element as HTMLInputElement).value).toBe("120");
        expect(wrapper.find('[data-testid="adjustment-channel-boss"]').attributes("disabled")).toBeDefined();
        await wrapper.find('[data-testid="adjustment-rate"]').setValue(80);
        expect(wrapper.find('[data-testid="adjustment-rate-hint"]').text()).toBe("予測を20%減らします（予測 × 0.8）。");
        vi.mocked(saveForecastAdjustment).mockResolvedValueOnce(adjustment({ id: 3, rate_percent: 80 }));
        await wrapper.find('[data-testid="adjustment-form"]').trigger("submit");
        await flushPromises();
        expect(saveForecastAdjustment).toHaveBeenLastCalledWith(expect.objectContaining({ channel: "amazon", starts_on: "2026-10", ends_on: null, rate_percent: 80, replaces: 1 }));

        // 取り消す前に確かめる
        const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
        await wrapper.find('[data-testid="adjustment-cancel"]').trigger("click");
        expect(cancelForecastAdjustment).not.toHaveBeenCalled();
        vi.mocked(cancelForecastAdjustment).mockResolvedValueOnce(adjustment({ status: "cancelled" }));
        await wrapper.find('[data-testid="adjustment-cancel"]').trigger("click");
        await flushPromises();
        expect(cancelForecastAdjustment).toHaveBeenCalledWith(1);
        confirm.mockRestore();

        await wrapper.find('[data-testid="adjustment-history-toggle"]').trigger("click");
        expect(wrapper.find('[data-testid="adjustment-history"]').text()).toContain("取り消した・BOSS 2026/11〜2027/1 120%");
        wrapper.unmount();
    });

    it("shows how much the adjustment changed the forecast in the breakdown and the tooltip", async () => {
        const response = itemTimeline();
        const boss = response.data[0]!.skus[0]!.detail!.channels[0]!;
        boss.monthly = boss.monthly!.map((month, index) => (index === 1 ? { ...month, quantity: 144, adjustment_rate: 1.2, adjustment_quantity: 24 } : month));
        const timelineBoss = response.data[0]!.skus[0]!.channels.boss!;
        timelineBoss.forecast = timelineBoss.forecast.map((value, index) => (index === 13 ? 144 : value));
        timelineBoss.adjustment = timelineBoss.adjustment.map((value, index) => (index === 13 ? 24 : value));
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        const { wrapper } = await mountView("/forecasts/fl-01?scope=boss");

        expect(wrapper.findAll('[data-testid="breakdown-adjustment"]').map((cell) => cell.text())).toEqual(["—", "+24（120%）", "—"]);
        expect(wrapper.findAll('[data-testid="breakdown-total"]')[1]!.text()).toBe("144");

        await wrapper.findAll('[data-testid="chart-band"]')[13]!.trigger("mouseenter");
        const rows = wrapper.findAll('[data-testid="chart-tooltip"] p').map((row) => row.text());
        expect(rows).toEqual(expect.arrayContaining(["BOSS の予測144個", "普段の予測120個", "補正で増減した数+24個"]));
        wrapper.unmount();
    });

    it("shows the item totals, the SKU chart and the basis of the forecast", async () => {
        const { wrapper } = await mountView("/forecasts/fl-01?scope=boss");

        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "month", period: null, itemNo: "fl-01" });
        expect(wrapper.find('[data-testid="item-no"]').text()).toBe("fl-01");
        expect(wrapper.find('[data-testid="sku-stat-forecast"]').text()).toBe("1,440個");
        expect(wrapper.find('[data-testid="sku-stat-share"]').text()).toBe("BOSS 100%");
        expect(wrapper.find('[data-testid="sku-stat-wape"]').text()).toBe("10.0%");
        expect(wrapper.find('[data-testid="sku-code"]').text()).toBe("fl-01-1");
        expect(wrapper.findAll('[data-testid="replay-tick"]')).toHaveLength(12);

        // 表示しているモール（BOSS）の根拠だけを出す
        expect(wrapper.find('[data-testid="basis-boss"] [data-testid="basis-average"]').text()).toBe("4.00 個/日");
        expect(wrapper.find('[data-testid="basis-amazon"]').exists()).toBe(false);
        // BOSS はモールごとに期間を出し、売れていないモールは期間を出さない
        expect(wrapper.find('[data-testid="basis-mall-rakuten"] [data-testid="basis-mall-average"]').text()).toBe("4.00");
        expect(wrapper.findAll('[data-testid="basis-mall-rakuten"] [data-testid="basis-window"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="basis-mall-yahoo"] [data-testid="basis-mall-note"]').text()).toBe("売れていない（0として足す）");
        expect(wrapper.findAll('[data-testid="basis-mall-yahoo"] [data-testid="basis-window"]')).toHaveLength(0);
        expect(wrapper.findAll('[data-testid="breakdown-total"]').map((cell) => cell.text())).toEqual(["120", "120", "140"]);
        expect(wrapper.findAll('[data-testid="breakdown-event"]')[2]!.text()).toBe("+20（楽天スー）");
        wrapper.unmount();
    });

    it("shows the daily breakdown and how much the events added to the forecast", async () => {
        // 楽天スーパーセール（10/14・15）の日は、BOSSの普段の予測120個に60個を足す
        const response = itemTimeline("day");
        const boss = response.data[0]!.skus[0]!.channels.boss!;
        boss.forecast = [null, null, null, 120, 180, 180, 120];
        boss.event = [null, null, null, 0, 60, 60, 0];
        response.data[0]!.skus[0]!.channels.amazon!.forecast = [null, null, null, 0.4, 0.4, 0.4, 0.4];
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        const { wrapper } = await mountView("/forecasts/fl-01?unit=day");

        expect(wrapper.find('[data-testid="monthly-breakdown"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="daily-breakdown"] h3').text()).toBe("日ごとの予測の内訳");
        // 今日から先の日だけ。Amazon は予測できないため入れない
        expect(wrapper.findAll('[data-testid="breakdown-total"]').map((cell) => cell.text())).toEqual(["120", "180", "180", "120"]);
        const event = wrapper.findAll('[data-testid="breakdown-event"]')[1]!;
        expect(event.text()).toBe("+60（楽天スー）");
        // 倍率は月ごとの内訳のイベントの倍率（BOSS は倍率をイベントのモールの分にだけ掛けるため、予測÷普段の予測とは限らない）
        expect(event.attributes("title")).toBe("楽天スーパーセール BOSS ×1.50");

        // ツールチップは、予測を普段の予測とイベントで増やした数に分け、1個未満の予測も0個と見せない
        await wrapper.findAll('[data-testid="chart-band"]')[4]!.trigger("mouseenter");
        const rows = wrapper.findAll('[data-testid="chart-tooltip"] p').map((row) => row.text());
        expect(rows).toEqual(expect.arrayContaining(["BOSS の予測180個", "普段の予測120個", "イベントで増やした数+60個", "Amazon の予測0.4個"]));
        wrapper.unmount();
    });

    it("shows why a channel cannot be forecast and goes back with the selections kept", async () => {
        const { wrapper, router } = await mountView("/forecasts/fl-01?unit=day&measure=amount");
        expect(fetchForecastTimeline).toHaveBeenCalledWith({ unit: "day", period: null, itemNo: "fl-01" });

        const response = itemTimeline();
        response.data[0]!.skus[0]!.unpredictable_reason_label = "Amazon：販売日数が足りない";
        vi.mocked(fetchForecastTimeline).mockResolvedValue(response);
        await router.replace({ query: {} });
        await flushPromises();
        expect(wrapper.find('[data-testid="sku-reason"]').text()).toBe("予測できません：Amazon：販売日数が足りない");
        expect(wrapper.find('[data-testid="basis-amazon"] [data-testid="basis-reason"]').text()).toBe("予測できません：販売日数が足りない");

        await wrapper.find('[data-testid="back-to-forecasts"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.name).toBe("forecasts");
        expect(router.currentRoute.value.query).toEqual({ item: "fl-01" });
        wrapper.unmount();
    });
});
