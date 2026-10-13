import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";
import ForecastAccuracyView from "@/views/ForecastAccuracyView.vue";
import { fetchForecastBacktest, fetchForecastBacktests, runForecastBacktest } from "@/api/forecastBacktests";
import { ApiError } from "@/api/client";
import type { BacktestMetrics, ForecastBacktest, ForecastBacktestSummaryResponse } from "@/types/api";

vi.mock("@/api/forecastBacktests", () => ({ fetchForecastBacktests: vi.fn(), fetchForecastBacktest: vi.fn(), runForecastBacktest: vi.fn() }));

function run(id: number, measuredAt: string): ForecastBacktest {
    return {
        id,
        measured_at: measuredAt,
        measured_by: "担当者",
        check_month_offset: 6,
        item_count: 25,
        sku_count: 272,
        duration_ms: 3200,
        horizons: [
            { horizon: "month_1", label: "1か月目", origin_from: "2025-10-01", origin_to: "2026-09-01", origin_count: 12 },
            { horizon: "months_3", label: "1～3か月目の合計", origin_from: "2025-08-01", origin_to: "2026-07-01", origin_count: 12 },
            { horizon: "check_month", label: "6か月後（判定する月）", origin_from: "2025-04-01", origin_to: "2026-03-01", origin_count: 12 },
        ],
    };
}

const m = (wape: number | null, bias: number | null = 0, actual = 100): BacktestMetrics => ({ evaluated: 10, skipped: 2, actual, forecast: actual, abs_error: (wape ?? 0) * actual, wape, bias });

/** 測っていない方式（イベントの補正より前の記録の、今の方式）。 */
const none: BacktestMetrics = { evaluated: 0, skipped: 0, actual: 0, forecast: 0, abs_error: 0, wape: null, bias: null };

/** withEvents が false なら、イベントの補正より前に測った記録。 */
function summary(backtest: ForecastBacktest, withEvents = true): ForecastBacktestSummaryResponse {
    const adjusted = (metrics: BacktestMetrics): BacktestMetrics => (withEvents ? metrics : none);
    return {
        data: {
            methods: [
                { method: "event_adjusted", label: "今の方式（イベントの補正あり）", ...adjusted(m(0.623, -0.02)) },
                { method: "base_average", label: "基準平均日販（補正なし）", ...m(0.631, 0.007) },
                { method: "recent_30", label: "直近30日の平均", ...m(0.678, 0.004) },
                { method: "last_year", label: "前年同月の実績", ...m(0.827, -0.278) },
            ],
            items: [
                {
                    item_no: "fisi-05",
                    brand: "FEELLIFE",
                    category: "老眼鏡",
                    methods: { event_adjusted: adjusted(m(0.57, 0.04, 3031)), base_average: m(0.581, 0.05, 3031), recent_30: m(0.696), last_year: m(0.799) },
                },
                {
                    item_no: "si-13",
                    brand: "FEELLIFE",
                    category: "調光老眼鏡",
                    methods: { event_adjusted: adjusted(m(0.69, 0.1, 500)), base_average: m(0.7, 0.1, 500), recent_30: m(0.75), last_year: m(0.5) },
                },
            ],
            origins: [{ origin: "2025-10-01", methods: { event_adjusted: adjusted(m(0.49)), base_average: m(0.5), recent_30: m(0.52), last_year: m(0.6) } }],
        },
        meta: { backtest, horizon: "month_1", channel: "total" },
    };
}

async function mountView(path = "/forecast-accuracy") {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:any(.*)", component: ForecastAccuracyView }] });
    await router.push(path);
    const wrapper = mount(ForecastAccuracyView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

describe("ForecastAccuracyView", () => {
    const latest = run(2, "2026-10-13T11:00:00+09:00");
    const older = run(1, "2026-10-01T09:00:00+09:00");

    beforeEach(() => {
        vi.mocked(fetchForecastBacktests).mockReset().mockResolvedValue([latest, older]);
        vi.mocked(fetchForecastBacktest)
            .mockReset()
            .mockImplementation(async (id) => (id === 1 ? summary(older, false) : summary(latest)));
        vi.mocked(runForecastBacktest).mockReset();
    });

    it("shows the error rate of each method, by item and by origin, for the latest measurement", async () => {
        const { wrapper } = await mountView();

        expect(wrapper.find("h1").text()).toBe("予測精度");
        expect(fetchForecastBacktest).toHaveBeenCalledWith(2, "month_1", "total");
        expect(wrapper.find('[data-testid="origin-note"]').text()).toContain("起点 2025/10〜2026/9 の12か月");
        expect(wrapper.find('[data-testid="method-event_adjusted"] [data-testid="method-wape"]').text()).toBe("62.3%");
        expect(wrapper.find('[data-testid="method-base_average"] [data-testid="method-wape"]').text()).toBe("63.1%");
        expect(wrapper.find('[data-testid="method-last_year"] [data-testid="method-bias"]').text()).toBe("偏り −27.8%（少なめに予測）");
        const rows = wrapper.findAll('[data-testid="item-row"]');
        expect(rows[0]!.text()).toContain("fisi-05");
        // その品番でいちばん外れ率の小さい方式を太字にする
        expect(rows[1]!.findAll('[data-testid="item-wape"]').map((cell) => cell.attributes("data-best") ?? "")).toEqual(["", "", "", "true"]);
        expect(wrapper.find('[data-testid="origin-row"]').text()).toContain("2025/10");
        expect(wrapper.find('[data-testid="old-run"]').exists()).toBe(false);
    });

    it("switches the horizon, the channel and the measurement, keeping them in the URL", async () => {
        const { wrapper, router } = await mountView();

        await wrapper.find('[data-testid="horizon-check_month"]').trigger("click");
        await flushPromises();
        await wrapper.find('[data-testid="channel-amazon"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.query).toEqual({ horizon: "check_month", channel: "amazon" });
        expect(fetchForecastBacktest).toHaveBeenLastCalledWith(2, "check_month", "amazon");
        // 判定する月は、起点の月から6か月先の月で答え合わせする
        expect(wrapper.find('[data-testid="origin-row"]').text()).toContain("2026/4");

        await wrapper.find('[data-testid="run-select"]').setValue("1");
        await flushPromises();
        expect(router.currentRoute.value.query.run).toBe("1");
        expect(fetchForecastBacktest).toHaveBeenLastCalledWith(1, "check_month", "amazon");
        expect(wrapper.find('[data-testid="old-run"]').exists()).toBe(true);
        // イベントの補正より前に測った記録には、今の方式（イベントの補正あり）を出さない
        expect(wrapper.find('[data-testid="method-event_adjusted"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="item-row"]').findAll('[data-testid="item-wape"]')).toHaveLength(3);
    });

    it("measures again and shows the new result, or why it could not", async () => {
        const newest = run(3, "2026-10-13T12:00:00+09:00");
        vi.mocked(runForecastBacktest).mockResolvedValue(newest);
        const { wrapper } = await mountView("/forecast-accuracy?run=1");
        vi.mocked(fetchForecastBacktests).mockResolvedValue([newest, latest, older]);

        await wrapper.find('[data-testid="measure"]').trigger("click");
        await flushPromises();
        expect(runForecastBacktest).toHaveBeenCalled();
        expect(fetchForecastBacktest).toHaveBeenLastCalledWith(3, "month_1", "total");
        expect(wrapper.find('[data-testid="old-run"]').exists()).toBe(false);

        vi.mocked(runForecastBacktest).mockRejectedValue(new ApiError("予測精度を測っています。終わるまでお待ちください。", 409));
        await wrapper.find('[data-testid="measure"]').trigger("click");
        await flushPromises();
        expect(wrapper.find('[data-testid="run-error"]').text()).toBe("予測精度を測っています。終わるまでお待ちください。");
    });

    it("asks to measure when nothing has been measured yet", async () => {
        vi.mocked(fetchForecastBacktests).mockResolvedValue([]);
        const { wrapper } = await mountView();

        expect(wrapper.find('[data-testid="empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="measure"]').text()).toBe("測る");
        expect(fetchForecastBacktest).not.toHaveBeenCalled();
    });
});
