import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import ForecastSnapshotPanel from "@/components/forecastAccuracy/ForecastSnapshotPanel.vue";
import { fetchForecastSnapshots, saveForecastSnapshot } from "@/api/forecastSnapshots";
import type { ForecastSnapshot, SnapshotMetrics } from "@/types/api";

vi.mock("@/api/forecastSnapshots", () => ({ fetchForecastSnapshots: vi.fn(), saveForecastSnapshot: vi.fn() }));

function metrics(overrides: Partial<SnapshotMetrics> = {}): SnapshotMetrics {
    return {
        evaluated: 2,
        adjusted: 1,
        actual: 180,
        forecast: 170,
        wape: 0.2778,
        item_wape: 0.0556,
        bias: -0.0556,
        unadjusted_forecast: 150,
        unadjusted_wape: 0.1667,
        unadjusted_item_wape: 0.1667,
        unadjusted_bias: -0.1667,
        ...overrides,
    };
}

function snapshot(id: number, forecastFrom: string, answered: boolean): ForecastSnapshot {
    return {
        id,
        taken_at: `${forecastFrom}T23:30:00+09:00`,
        forecast_from: forecastFrom,
        trigger: "schedule",
        trigger_label: "毎月1日の自動保存",
        created_by: null,
        check_month_offset: 6,
        channels: [
            { channel: "boss", from: "2023-07-11", to: "2026-08-31" },
            { channel: "amazon", from: "2023-01-01", to: "2026-08-31" },
        ],
        item_count: 27,
        sku_count: 295,
        adjustment_count: 1,
        evaluations: {
            month_1: {
                months: [forecastFrom.slice(0, 7)],
                available_from: "2026-11",
                channels: { total: answered ? metrics() : null, boss: answered ? metrics({ adjusted: 0 }) : null, amazon: null },
            },
        },
    };
}

describe("ForecastSnapshotPanel", () => {
    beforeEach(() => {
        vi.mocked(fetchForecastSnapshots)
            .mockReset()
            .mockResolvedValue([snapshot(2, "2026-10-01", false), snapshot(1, "2026-09-01", true)]);
        vi.mocked(saveForecastSnapshot).mockReset();
    });

    it("compares the saved forecasts with the actual sales after and before the adjustment", async () => {
        const wrapper = mount(ForecastSnapshotPanel, { props: { horizon: "month_1", channel: "total" } });
        await flushPromises();

        const rows = wrapper.findAll('[data-testid="snapshot-row"]');
        expect(rows).toHaveLength(2);
        // まだ販売実績がそろっていない版は、答え合わせできる月を出す
        expect(rows[0]!.find('[data-testid="snapshot-pending"]').text()).toBe("販売実績がそろう 2026/11から答え合わせできます");
        expect(rows[1]!.find('[data-testid="snapshot-wape"]').text()).toBe("27.8%");
        expect(rows[1]!.find('[data-testid="snapshot-unadjusted-wape"]').text()).toContain("16.7%");
        expect(rows[1]!.find('[data-testid="snapshot-improved"]').text()).toBe("補正で11.1ポイント悪化");
        expect(rows[1]!.text()).toContain("BOSS 8/31・Amazon 8/31 まで");

        // 補正を掛けたSKUが無ければ、補正の前の外れ率は出さない
        await wrapper.setProps({ channel: "boss" });
        expect(wrapper.findAll('[data-testid="snapshot-row"]')[1]!.find('[data-testid="snapshot-unadjusted-wape"]').text()).toBe("補正なし");
        wrapper.unmount();
    });

    it("saves the current forecast and reloads the list", async () => {
        vi.mocked(fetchForecastSnapshots).mockResolvedValueOnce([]);
        const wrapper = mount(ForecastSnapshotPanel, { props: { horizon: "month_1", channel: "total" } });
        await flushPromises();
        expect(wrapper.find('[data-testid="snapshot-empty"]').exists()).toBe(true);

        vi.mocked(saveForecastSnapshot).mockResolvedValueOnce({ id: 3, item_count: 27, sku_count: 295 });
        await wrapper.find('[data-testid="snapshot-save"]').trigger("click");
        await flushPromises();
        expect(saveForecastSnapshot).toHaveBeenCalled();
        expect(wrapper.find('[data-testid="snapshot-message"]').text()).toBe("今の需要予測を保存しました（27品番・295 SKU）。");
        expect(wrapper.findAll('[data-testid="snapshot-row"]')).toHaveLength(2);
        wrapper.unmount();
    });
});
