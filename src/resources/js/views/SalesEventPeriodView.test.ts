import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { fetchSalesEvent, fetchSalesEventPeriod, saveSalesEventPeriodMultipliers } from "@/api/salesEvents";
import { ApiError } from "@/api/client";
import SalesEventPeriodDetail from "@/components/salesEvent/SalesEventPeriodDetail.vue";
import { meta, mountAt, periodDetail, salePeriods, withPeriods } from "@/views/salesEvent.fixture";

vi.mock("@/api/salesEvents", () => ({
    fetchSalesEvents: vi.fn(),
    fetchSalesEvent: vi.fn(),
    createSalesEvent: vi.fn(),
    updateSalesEvent: vi.fn(),
    deleteSalesEvent: vi.fn(),
    createSalesEventPeriod: vi.fn(),
    updateSalesEventPeriod: vi.fn(),
    deleteSalesEventPeriod: vi.fn(),
    fetchSalesEventPeriod: vi.fn(),
    saveSalesEventPeriodMultipliers: vi.fn(),
}));

describe("SalesEventPeriodView", () => {
    beforeEach(() => {
        vi.mocked(fetchSalesEventPeriod).mockReset().mockResolvedValue(periodDetail());
        vi.mocked(fetchSalesEvent)
            .mockReset()
            .mockResolvedValue({ data: withPeriods(salePeriods), meta });
        vi.mocked(saveSalesEventPeriodMultipliers).mockReset();
    });

    it("shows the multipliers of an upcoming period and saves the ones entered for that period", async () => {
        vi.mocked(saveSalesEventPeriodMultipliers).mockResolvedValue(periodDetail(3.5));
        const { wrapper } = await mountAt("/sales-events/1/periods/13?mall=rakuten");

        expect(fetchSalesEventPeriod).toHaveBeenCalledWith(13);
        expect(wrapper.find("h1").text()).toContain("楽天スーパーセール");
        expect(wrapper.find("h1").text()).toContain("2026/12/4〜12/7");
        expect(wrapper.find('[data-testid="period-status"]').text()).toBe("これから");
        expect(wrapper.find('[data-testid="period-summary"]').text()).toContain("4日間・楽天市場");
        const detail = wrapper.find('[data-testid="event-detail"]');
        expect(detail.find('[data-testid="candidate-summary"]').text()).toContain("このイベントの過去の期間 2回");
        expect(detail.findAll('[data-testid="candidate-basis"] li')).toHaveLength(2);

        await detail.findAll('[data-testid="multiplier-input"]')[0]!.setValue("3.5");
        await wrapper.find('[data-testid="save-multipliers"]').trigger("click");
        await flushPromises();
        expect(saveSalesEventPeriodMultipliers).toHaveBeenCalledWith(13, [{ item_no: "fl-01", channel: "boss", multiplier: 3.5 }]);
        expect(wrapper.find('[data-testid="saved-note"]').text()).toContain("1品番の倍率を保存しました。");
        wrapper.unmount();
    });

    it("goes back to the event page and the list with the mall", async () => {
        const { wrapper, router } = await mountAt("/sales-events/1/periods/13?mall=rakuten");

        expect(wrapper.find('[data-testid="back-to-event"]').text()).toBe("楽天スーパーセール");
        await wrapper.find('[data-testid="back-to-event"]').trigger("click");
        await flushPromises();
        expect(router.currentRoute.value.name).toBe("sales-event");
        expect(router.currentRoute.value.params).toEqual({ eventId: "1" });
        expect(router.currentRoute.value.query).toEqual({ mall: "rakuten" });
        wrapper.unmount();
    });

    it("corrects the event in the URL, and shows that a deleted period was not found", async () => {
        const { wrapper, router } = await mountAt("/sales-events/2/periods/13");
        expect(router.currentRoute.value.params).toEqual({ eventId: "1", periodId: "13" });
        wrapper.unmount();

        vi.mocked(fetchSalesEventPeriod).mockRejectedValue(new ApiError("見つかりません。", 404));
        const missing = await mountAt("/sales-events/1/periods/99");
        expect(missing.wrapper.find('[data-testid="not-found"]').text()).toContain("この期間は見つかりませんでした。");
        missing.wrapper.unmount();
    });

    it("shows ended period items in descending change rate order, with unmeasurable items last", () => {
        const endedDetail = periodDetail();
        endedDetail.data.status = "ended";
        endedDetail.data.status_label = "終了";
        endedDetail.data.channels = [
            {
                channel: "boss",
                label: "BOSS",
                kind: "measured",
                overall: 2,
                expected: 10,
                actual: 20,
                basis: [],
                note: null,
                items: [
                    { item_no: "fl-02", brand: "FEELLIFE", category: "老眼鏡", expected: 5, actual: 10, ratio: 2 },
                    { item_no: "fl-04", brand: "FEELLIFE", category: "老眼鏡", expected: null, actual: null, ratio: null },
                    { item_no: "fl-03", brand: "FEELLIFE", category: "老眼鏡", expected: 1, actual: 6, ratio: 6 },
                    { item_no: "fl-01", brand: "FEELLIFE", category: "老眼鏡", expected: 1, actual: 6, ratio: 6 },
                ],
            },
        ];
        const wrapper = mount(SalesEventPeriodDetail, { props: { detail: endedDetail, isLoading: false } });

        const rows = wrapper.findAll('[data-testid="measured-row"]');
        expect(rows.map((row) => row.find("th span").text())).toEqual(["fl-01", "fl-03", "fl-02", "fl-04"]);
        expect(rows.map((row) => row.find("td:last-child").text())).toEqual(["6.00倍", "6.00倍", "2.00倍", "—"]);
        expect(endedDetail.data.channels[0]?.items.map((item) => item.item_no)).toEqual(["fl-02", "fl-04", "fl-03", "fl-01"]);
        expect(wrapper.find('[data-testid="save-multipliers"]').exists()).toBe(false);
        wrapper.unmount();
    });
});
