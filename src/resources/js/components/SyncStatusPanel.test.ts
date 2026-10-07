import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import SyncStatusPanel from "@/components/SyncStatusPanel.vue";
import type { SyncRecordBase, SyncState } from "@/types/api";

interface TestSync extends SyncRecordBase {
    count: number | null;
}

function sync(overrides: Partial<TestSync>): TestSync {
    return {
        id: 1,
        status: "succeeded",
        status_label: "成功",
        triggered_by: "schedule",
        triggered_by_label: "自動",
        started_at: "2026-10-06T06:00:00+09:00",
        finished_at: "2026-10-06T06:00:05+09:00",
        error_message: null,
        count: 55,
        ...overrides,
    };
}

const loadState = vi.fn<() => Promise<SyncState<TestSync>>>();
const runSync = vi.fn<() => Promise<TestSync>>();

async function mountPanel() {
    const wrapper = mount(SyncStatusPanel<TestSync>, {
        props: { source: "CrossWalker", schedule: "毎朝6時", subject: "品番とSKU", loadState, runSync, summarize: (s: TestSync) => `品番 ${s.count}件` },
    });
    await flushPromises();
    return wrapper;
}

describe("SyncStatusPanel", () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        loadState.mockReset();
        runSync.mockReset();
    });

    it("tells the user to fetch when nothing has been fetched yet", async () => {
        loadState.mockResolvedValue({ latest: null, last_succeeded: null });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("CrossWalkerからまだ取得していません。");
        expect(wrapper.text()).toContain("以後は毎朝6時に自動で取得します。");
        expect(wrapper.text()).toContain("「取得実行」を押すと、CrossWalkerから品番とSKUを取り込みます。");
        expect(wrapper.find("button").text()).toBe("取得実行");
    });

    it("shows the last fetch time and the summary after a successful fetch", async () => {
        const succeeded = sync({});
        loadState.mockResolvedValue({ latest: succeeded, last_succeeded: succeeded });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("最終取得 2026/10/06 06:00");
        expect(wrapper.text()).toContain("品番 55件。毎朝6時に自動で取得します。");
    });

    it("shows the failure reason and how old the displayed data is when the last fetch failed", async () => {
        loadState.mockResolvedValue({
            latest: sync({ id: 2, status: "failed", triggered_by_label: "手動", finished_at: "2026-10-06T09:30:00+09:00", count: null, error_message: "CrossWalkerに接続できませんでした。" }),
            last_succeeded: sync({}),
        });

        const wrapper = await mountPanel();

        expect(wrapper.text()).toContain("2026/10/06 09:30 の取得（手動）に失敗しました。");
        expect(wrapper.text()).toContain("CrossWalkerに接続できませんでした。");
        expect(wrapper.text()).toContain("表示中のデータは 2026/10/06 06:00 に取得したものです。");
    });

    it("runs a fetch, refreshes the status and notifies the parent", async () => {
        const manual = sync({ id: 3, triggered_by: "manual", triggered_by_label: "手動" });
        loadState.mockResolvedValueOnce({ latest: null, last_succeeded: null }).mockResolvedValueOnce({ latest: manual, last_succeeded: manual });
        runSync.mockResolvedValue(manual);
        const wrapper = await mountPanel();

        await wrapper.find("button").trigger("click");
        await flushPromises();

        expect(runSync).toHaveBeenCalledOnce();
        expect(wrapper.text()).toContain("（手動）");
        expect(wrapper.emitted("synced")).toEqual([[manual]]);
    });
});
