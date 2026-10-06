import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import CrossWalkerSyncPanel from "@/components/CrossWalkerSyncPanel.vue";
import { fetchLatestCrossWalkerSync, runCrossWalkerSync } from "@/api/crosswalkerSyncs";
import type { CrossWalkerSync } from "@/types/api";

vi.mock("@/api/crosswalkerSyncs", () => ({ fetchLatestCrossWalkerSync: vi.fn(), runCrossWalkerSync: vi.fn() }));

function sync(overrides: Partial<CrossWalkerSync>): CrossWalkerSync {
    return {
        id: 1,
        status: "succeeded",
        status_label: "成功",
        triggered_by: "schedule",
        triggered_by_label: "自動",
        started_at: "2026-10-06T06:00:00+09:00",
        finished_at: "2026-10-06T06:00:05+09:00",
        item_count: 55,
        sku_count: 581,
        added_item_count: 0,
        removed_item_count: 0,
        detached_sku_count: 0,
        error_message: null,
        ...overrides,
    };
}

async function mountPanel() {
    const wrapper = mount(CrossWalkerSyncPanel);
    await flushPromises();
    return wrapper;
}

describe("CrossWalkerSyncPanel", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(fetchLatestCrossWalkerSync).mockReset();
        vi.mocked(runCrossWalkerSync).mockReset();
    });

    it("tells the user to fetch when nothing has been fetched yet", async () => {
        vi.mocked(fetchLatestCrossWalkerSync).mockResolvedValue({ latest: null, last_succeeded: null });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("CrossWalkerからまだ取得していません。");
    });

    it("shows the last fetch time and counts after a successful fetch", async () => {
        const succeeded = sync({ added_item_count: 2 });
        vi.mocked(fetchLatestCrossWalkerSync).mockResolvedValue({ latest: succeeded, last_succeeded: succeeded });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("最終取得 2026/10/06 06:00");
        expect(wrapper.text()).toContain("品番 55件・SKU 581件（追加 2件）");
    });

    it("shows the failure reason and how old the displayed data is when the last fetch failed", async () => {
        vi.mocked(fetchLatestCrossWalkerSync).mockResolvedValue({
            latest: sync({ id: 2, status: "failed", triggered_by_label: "手動", finished_at: "2026-10-06T09:30:00+09:00", item_count: null, error_message: "CrossWalkerに接続できませんでした。" }),
            last_succeeded: sync({}),
        });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("2026/10/06 09:30 の取得（手動）に失敗しました。");
        expect(wrapper.text()).toContain("CrossWalkerに接続できませんでした。");
        expect(wrapper.text()).toContain("表示中のデータは 2026/10/06 06:00 に取得したものです。");
    });

    it("runs a fetch, refreshes the status and notifies the parent", async () => {
        const manual = sync({ id: 3, triggered_by: "manual", triggered_by_label: "手動" });
        vi.mocked(fetchLatestCrossWalkerSync).mockResolvedValueOnce({ latest: null, last_succeeded: null }).mockResolvedValueOnce({ latest: manual, last_succeeded: manual });
        vi.mocked(runCrossWalkerSync).mockResolvedValue(manual);
        const wrapper = await mountPanel();

        await wrapper.find("button").trigger("click");
        await flushPromises();

        expect(runCrossWalkerSync).toHaveBeenCalledOnce();
        expect(wrapper.text()).toContain("（手動）");
        expect(wrapper.emitted("synced")).toEqual([[manual]]);
    });
});
