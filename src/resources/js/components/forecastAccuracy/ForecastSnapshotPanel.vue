<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from "vue";
import { fetchForecastSnapshots, saveForecastSnapshot } from "@/api/forecastSnapshots";
import { formatBias, formatWape } from "@/components/forecastAccuracy/accuracyFormat";
import { formatDateTime } from "@/components/salesTarget/format";
import LoadingState from "@/components/ui/LoadingState.vue";
import type { BacktestHorizon, ForecastSnapshot, SnapshotMetrics } from "@/types/api";

/**
 * 保存した需要予測の版（バックログ B-108、決定記録 K-136）と、その答え合わせ。予測精度の画面の下に置く。
 * 上の予測精度は今の計算方法で過去をやり直した数で測るのに対し、ここでは実際に出していた予測（毎月1日に自動で保存した版）を、その後の販売実績と比べる。
 * 手動での補正（B-109）で当たるようになったかを見るため、補正の後と前の外れ率を並べる。何か月先とチャネルは、画面の上で選んだものを使う。
 */

const props = defineProps<{ horizon: BacktestHorizon; channel: "total" | "boss" | "amazon" }>();

const snapshots = shallowRef<ForecastSnapshot[] | null>(null);
const isSaving = ref(false);
const message = ref<string | null>(null);

async function load(): Promise<void> {
    try {
        snapshots.value = await fetchForecastSnapshots();
    } catch {
        snapshots.value = [];
    }
}
onMounted(load);

async function save(): Promise<void> {
    if (isSaving.value) {
        return;
    }
    isSaving.value = true;
    message.value = null;
    try {
        const saved = await saveForecastSnapshot();
        message.value = `今の需要予測を保存しました（${saved.item_count}品番・${saved.sku_count} SKU）。`;
        await load();
    } catch {
        // 通信エラーは全体通知に表示される
    } finally {
        isSaving.value = false;
    }
}

/** 補正で外れ率がどれだけ変わったか（外れ率の差なのでポイントで言う）。 */
const improvedText = (difference: number): string => {
    const points = (Math.abs(difference) * 100).toFixed(1);
    return difference < 0 ? `補正で${points}ポイント改善` : difference > 0 ? `補正で${points}ポイント悪化` : "補正で変わらない";
};
const monthLabel = (value: string): string => `${value.slice(0, 4)}/${Number(value.slice(5, 7))}`;
const shortDate = (value: string | null): string => (value === null ? "—" : `${Number(value.slice(5, 7))}/${Number(value.slice(8, 10))}`);

const rows = computed(() =>
    (snapshots.value ?? []).map((snapshot) => {
        const evaluation = snapshot.evaluations[props.horizon];
        const metrics: SnapshotMetrics | null = evaluation?.channels[props.channel] ?? null;
        const months = evaluation?.months ?? [];
        return {
            snapshot,
            metrics,
            months: months.length === 0 ? "—" : months.length === 1 ? monthLabel(months[0]!) : `${monthLabel(months[0]!)}〜${monthLabel(months.at(-1)!)}`,
            pending: evaluation ? `${monthLabel(evaluation.available_from)}から` : "—",
            salesTo: snapshot.channels.map((channel) => `${channel.channel === "boss" ? "BOSS" : "Amazon"} ${shortDate(channel.to)}`).join("・"),
            improved: metrics !== null && metrics.adjusted > 0 && metrics.wape !== null && metrics.unadjusted_wape !== null ? metrics.wape - metrics.unadjusted_wape : null,
        };
    }),
);
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="snapshot-heading" data-testid="snapshot-panel">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 px-4 py-3">
            <div class="flex max-w-[760px] flex-col gap-1">
                <h2 id="snapshot-heading" class="m-0 text-sm font-semibold text-stone-900">実際に出した予測の当たり具合</h2>
                <p class="m-0 text-xs leading-relaxed text-stone-600">
                    毎月1日の終わりに、その時点の需要予測（12か月分）を版として自動で保存し、その後の販売実績と比べます。上の予測精度は今の計算方法で過去をやり直した数で測りますが、ここは実際に出していた予測です。手動での補正の前と後の外れ率を並べ、補正で当たるようになったかを確かめられます。
                </p>
            </div>
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-stone-300 px-3 text-[13px] font-medium text-stone-800 hover:bg-stone-50 disabled:cursor-wait disabled:opacity-60"
                :disabled="isSaving"
                data-testid="snapshot-save"
                @click="save"
            >
                {{ isSaving ? "保存しています…" : "今の予測を保存" }}
            </button>
        </div>
        <p v-if="message" class="m-0 border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs text-stone-700" role="status" data-testid="snapshot-message">{{ message }}</p>

        <LoadingState v-if="snapshots === null" message="読み込んでいます…" class="rounded-none border-0" />
        <p v-else-if="snapshots.length === 0" class="m-0 px-4 py-10 text-center text-sm text-stone-500" data-testid="snapshot-empty">
            まだ保存した予測はありません。毎月1日に自動で保存されます。「今の予測を保存」で今すぐ保存することもできます。
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[56rem] border-separate border-spacing-0 text-sm" data-testid="snapshot-table">
                <thead class="text-xs text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                    <tr>
                        <th scope="col" class="text-left">保存した日時</th>
                        <th scope="col" class="text-left">使った販売実績</th>
                        <th scope="col" class="text-left">答え合わせする月</th>
                        <th scope="col" class="text-right">外れ率</th>
                        <th scope="col" class="text-right">補正の前</th>
                        <th scope="col" class="text-right">品番の合計で比べると</th>
                        <th scope="col" class="text-right">偏り</th>
                    </tr>
                </thead>
                <tbody class="[&_td]:border-b [&_td]:border-stone-100 [&_td]:px-4 [&_td]:py-2">
                    <tr v-for="row in rows" :key="row.snapshot.id" data-testid="snapshot-row">
                        <td class="whitespace-nowrap">
                            <span class="text-stone-900 tabular-nums">{{ formatDateTime(row.snapshot.taken_at) }}</span>
                            <span class="block text-[11px] text-stone-500"
                                >{{ row.snapshot.trigger_label }}{{ row.snapshot.created_by ? `（${row.snapshot.created_by}）` : "" }}・{{ row.snapshot.item_count }}品番・{{
                                    row.snapshot.sku_count
                                }}
                                SKU<template v-if="row.snapshot.adjustment_count > 0">・補正 {{ row.snapshot.adjustment_count }}件</template></span
                            >
                        </td>
                        <td class="text-xs whitespace-nowrap text-stone-600 tabular-nums">{{ row.salesTo }} まで</td>
                        <td class="whitespace-nowrap text-stone-700 tabular-nums">{{ row.months }}</td>
                        <template v-if="row.metrics">
                            <td class="text-right font-semibold text-stone-900 tabular-nums" data-testid="snapshot-wape">{{ formatWape(row.metrics.wape) }}</td>
                            <td class="text-right tabular-nums" data-testid="snapshot-unadjusted-wape">
                                <template v-if="row.metrics.adjusted === 0"><span class="text-stone-400" title="補正を掛けたSKUがありません">補正なし</span></template>
                                <template v-else>
                                    <span class="text-stone-600">{{ formatWape(row.metrics.unadjusted_wape) }}</span>
                                    <span
                                        v-if="row.improved !== null"
                                        class="block text-[11px]"
                                        :class="row.improved < 0 ? 'text-emerald-700' : row.improved > 0 ? 'text-amber-700' : 'text-stone-500'"
                                        data-testid="snapshot-improved"
                                        >{{ improvedText(row.improved) }}</span
                                    >
                                </template>
                            </td>
                            <td class="text-right text-stone-700 tabular-nums">{{ formatWape(row.metrics.item_wape) }}</td>
                            <td class="text-right text-stone-600 tabular-nums">{{ formatBias(row.metrics.bias) }}</td>
                        </template>
                        <td v-else colspan="4" class="text-right text-xs text-stone-500" data-testid="snapshot-pending">販売実績がそろう {{ row.pending }}答え合わせできます</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="snapshots && snapshots.length > 0" class="m-0 border-t border-stone-200 px-4 py-2.5 text-[11px] text-stone-500">
            何か月先とチャネルは、画面の上で選んだものです。そのチャネルの対象品番（保存したときのもの）のSKUで、答え合わせの月をすべて予測できていたものだけを比べます。
        </p>
    </section>
</template>
