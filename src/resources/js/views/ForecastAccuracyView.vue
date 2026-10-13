<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchForecastBacktest, fetchForecastBacktests, runForecastBacktest } from "@/api/forecastBacktests";
import { bestMethod, biasLabel, evaluatedMonths, formatBias, formatCount, formatWape, measuredMethods, originLabel } from "@/components/forecastAccuracy/accuracyFormat";
import { formatDateTime } from "@/components/salesTarget/format";
import type { BacktestHorizon, ForecastBacktest, ForecastBacktestSummaryResponse } from "@/types/api";

/**
 * 予測精度（バックログ B-130、決定記録 K-111）。左メニュー「在庫試算」の需要予測の下に置く。過去の月初に戻って、その前日までの販売実績だけで予測し直し、
 * その後の販売実績と比べた当たり具合（WAPE・偏り）を、今の方式（イベント＋登録した季節係数、B-129・B-106）、イベントの補正だけ、補正を入れる前の方式と、
 * 比べるための単純な方式（直近30日の平均・前年同月の実績）で並べて見る。イベントの補正より前に測った記録では、測っていない今の方式を出さない。
 * 何か月先（1か月目・1～3か月目の合計・判定する月）とチャネル（全体・BOSS・Amazon）を切り替え、方式ごと・品番ごと・起点ごとに見られる。
 * 「測り直す」で今の販売実績で測り直し、結果は測った日時とともに残す（最新20件）。前に測った結果も選んで見られ、予測の方式を変えた前後で比べられる。
 * 選んだ記録・何か月先・チャネルは URL に持たせる。
 */

type Channel = "total" | "boss" | "amazon";

const HORIZONS: BacktestHorizon[] = ["month_1", "months_3", "check_month"];
const CHANNELS: { value: Channel; label: string }[] = [
    { value: "total", label: "全体" },
    { value: "boss", label: "BOSS" },
    { value: "amazon", label: "Amazon" },
];

const route = useRoute();
const router = useRouter();

const horizon = computed<BacktestHorizon>(() => (HORIZONS.includes(route.query.horizon as BacktestHorizon) ? (route.query.horizon as BacktestHorizon) : "month_1"));
const channel = computed<Channel>(() => (route.query.channel === "boss" || route.query.channel === "amazon" ? route.query.channel : "total"));
const selectedRunId = computed<number | null>(() => (typeof route.query.run === "string" && /^\d+$/.test(route.query.run) ? Number(route.query.run) : null));

function updateQuery(changes: { horizon?: BacktestHorizon; channel?: Channel; run?: number | null }): void {
    const next = { horizon: changes.horizon ?? horizon.value, channel: changes.channel ?? channel.value, run: changes.run !== undefined ? changes.run : selectedRunId.value };
    void router.replace({
        query: {
            ...route.query,
            horizon: next.horizon === "month_1" ? undefined : next.horizon,
            channel: next.channel === "total" ? undefined : next.channel,
            run: next.run === null ? undefined : String(next.run),
        },
    });
}

const runs = shallowRef<ForecastBacktest[] | null>(null);
const result = shallowRef<ForecastBacktestSummaryResponse | null>(null);
const isLoading = ref(false);
const isRunning = ref(false);
const hasError = ref(false);
const runError = ref<string | null>(null);

/** 見ている記録（選んでいなければ最新）。 */
const currentRun = computed<ForecastBacktest | null>(() => runs.value?.find((run) => run.id === selectedRunId.value) ?? runs.value?.[0] ?? null);
const isLatest = computed(() => currentRun.value !== null && currentRun.value.id === runs.value?.[0]?.id);

async function loadRuns(): Promise<void> {
    try {
        runs.value = await fetchForecastBacktests();
    } catch {
        hasError.value = true;
    }
}

const requestKey = computed(() => JSON.stringify([currentRun.value?.id ?? null, horizon.value, channel.value]));

async function loadSummary(): Promise<void> {
    const run = currentRun.value;
    if (!run) {
        result.value = null;
        return;
    }
    const key = requestKey.value;
    isLoading.value = true;
    hasError.value = false;
    try {
        const response = await fetchForecastBacktest(run.id, horizon.value, channel.value);
        // 読み込み中に選択を変えたときは、古い方の結果を表示しない
        if (key === requestKey.value) {
            result.value = response;
        }
    } catch {
        hasError.value = true;
    } finally {
        isLoading.value = false;
    }
}

async function measure(): Promise<void> {
    isRunning.value = true;
    runError.value = null;
    try {
        const run = await runForecastBacktest();
        await loadRuns();
        updateQuery({ run: null });
        if (currentRun.value?.id === run.id) {
            await loadSummary();
        }
    } catch (error) {
        runError.value = error instanceof ApiError ? error.message : "予測精度を測れませんでした。";
    } finally {
        isRunning.value = false;
    }
}

onMounted(async () => {
    await loadRuns();
    await loadSummary();
});
watch(requestKey, () => void loadSummary());

const horizonOptions = computed(() => currentRun.value?.horizons ?? []);
const currentHorizon = computed(() => horizonOptions.value.find((item) => item.horizon === horizon.value) ?? null);
const checkMonthOffset = computed(() => currentRun.value?.check_month_offset ?? 6);

const runLabel = (run: ForecastBacktest): string => `${formatDateTime(run.measured_at)}${run.measured_by ? `（${run.measured_by}）` : ""}`;

/** 起点の範囲の説明。 */
const originNote = computed(() => {
    const item = currentHorizon.value;
    if (!item?.origin_from || !item.origin_to) {
        return "";
    }
    return `起点 ${originLabel(item.origin_from)}〜${originLabel(item.origin_to)} の${item.origin_count}か月。各起点（月の1日）の前日までの販売実績だけで予測し、その後の販売実績と比べました。`;
});

const methods = computed(() => measuredMethods(result.value?.data.methods ?? []));
/** その記録の今の方式（季節係数・イベントの補正より前に測った記録では、そのときの方式）。 */
const currentMethod = computed(() => methods.value[0]?.value ?? "event_adjusted");
const methodCards = computed(() => (result.value?.data.methods ?? []).filter((card) => methods.value.some((method) => method.value === card.method)));
const items = computed(() => result.value?.data.items ?? []);
const origins = computed(() => result.value?.data.origins ?? []);
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <p class="text-xs text-stone-500">在庫試算 / 予測精度</p>
                <h1 class="text-[26px] leading-tight font-bold tracking-tight text-stone-900">予測精度</h1>
                <p class="max-w-3xl text-[13px] text-stone-600" data-testid="description">
                    需要予測が過去にどれだけ当たったかを測ります。過去の月初に戻ってその前日までの販売実績だけで予測し直し、その後の販売実績と比べます。今の方式（イベント＋季節係数）のほかに、イベントの補正だけ・補正を入れる前の方式と、目安として単純な方式（直近30日の平均・前年同月の実績）も測ります。
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label v-if="runs && runs.length > 0" class="flex items-center gap-2 text-xs text-stone-500">
                    測った日時
                    <select
                        class="h-10 rounded-lg border border-stone-300 bg-white px-3 text-[13px] text-stone-900 tabular-nums focus:border-stone-900 focus:outline-none"
                        :value="currentRun?.id"
                        data-testid="run-select"
                        @change="updateQuery({ run: Number(($event.target as HTMLSelectElement).value) === runs[0]?.id ? null : Number(($event.target as HTMLSelectElement).value) })"
                    >
                        <option v-for="(run, index) in runs" :key="run.id" :value="run.id">{{ runLabel(run) }}{{ index === 0 ? "・最新" : "" }}</option>
                    </select>
                </label>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-lg bg-stone-900 px-4 text-[13px] font-semibold text-white hover:bg-stone-800 disabled:cursor-wait disabled:opacity-60"
                    :disabled="isRunning"
                    data-testid="measure"
                    @click="measure"
                >
                    <svg class="size-4" :class="isRunning ? 'animate-spin' : ''" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" />
                        <path d="M13.5 2.5v3h-3" />
                    </svg>
                    {{ isRunning ? "測っています…" : runs && runs.length > 0 ? "測り直す" : "測る" }}
                </button>
            </div>
        </header>

        <p v-if="runError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert" data-testid="run-error">{{ runError }}</p>

        <div v-if="hasError" class="flex flex-col items-center gap-3 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-stone-600">予測精度を表示できませんでした。</p>
            <button type="button" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-100" @click="loadRuns().then(loadSummary)">
                再読み込み
            </button>
        </div>

        <div v-else-if="runs === null" class="rounded-xl border border-stone-200 bg-white px-6 py-16 text-center text-sm text-stone-500">読み込み中…</div>

        <div v-else-if="runs.length === 0" class="flex flex-col items-center gap-2 rounded-xl border border-stone-200 bg-white px-6 py-16 text-center" data-testid="empty">
            <p class="text-sm text-stone-700">まだ予測精度を測っていません。</p>
            <p class="text-xs text-stone-500">「測る」を押すと、対象品番のSKUについて過去12か月分を測ります（数秒かかります）。</p>
        </div>

        <template v-else>
            <p v-if="!isLatest" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-900" data-testid="old-run">
                前に測った結果（{{ currentRun ? runLabel(currentRun) : "" }}）を見ています。
                <button type="button" class="font-semibold underline" @click="updateQuery({ run: null })">最新の結果を見る</button>
            </p>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" :aria-busy="isLoading" aria-labelledby="accuracy-summary">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3.5">
                    <h2 id="accuracy-summary" class="sr-only">方式ごとの当たり具合</h2>
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="tablist" aria-label="何か月先">
                        <button
                            v-for="option in horizonOptions"
                            :key="option.horizon"
                            type="button"
                            role="tab"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="horizon === option.horizon ? 'bg-stone-900 font-semibold text-white' : 'text-stone-600 hover:text-stone-900'"
                            :aria-selected="horizon === option.horizon"
                            :data-testid="`horizon-${option.horizon}`"
                            @click="updateQuery({ horizon: option.horizon })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="inline-flex gap-0.5 rounded-[9px] bg-stone-100 p-0.75" role="group" aria-label="チャネル">
                        <button
                            v-for="option in CHANNELS"
                            :key="option.value"
                            type="button"
                            class="inline-flex h-8 items-center rounded-[7px] px-3 text-[13px]"
                            :class="channel === option.value ? 'bg-white font-semibold text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900'"
                            :aria-pressed="channel === option.value"
                            :data-testid="`channel-${option.value}`"
                            @click="updateQuery({ channel: option.value })"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
                <p class="border-b border-stone-200 px-4 py-2.5 text-xs text-stone-600" data-testid="origin-note">
                    {{ originNote }} 対象は測ったときの対象品番（{{ currentRun?.item_count }}品番・{{ currentRun?.sku_count }} SKU）です。
                </p>

                <div v-if="!result" class="px-6 py-12 text-center text-sm text-stone-500">読み込み中…</div>
                <div v-else class="grid gap-3 p-4 transition-opacity sm:grid-cols-2 xl:grid-cols-4" :class="isLoading ? 'opacity-50' : ''">
                    <div
                        v-for="card in methodCards"
                        :key="card.method"
                        class="flex flex-col gap-1.5 rounded-lg border px-4 py-3.5"
                        :class="card.method === currentMethod ? 'border-stone-900 shadow-[inset_0_0_0_1px_var(--color-stone-900)]' : 'border-stone-200'"
                        :data-testid="`method-${card.method}`"
                    >
                        <span class="text-xs font-medium text-stone-600">{{ card.label }}</span>
                        <span class="flex items-baseline gap-2">
                            <strong class="text-[28px] leading-none font-bold text-stone-900 tabular-nums" data-testid="method-wape">{{ formatWape(card.wape) }}</strong>
                            <span class="text-xs text-stone-500">外れ率（WAPE）</span>
                        </span>
                        <span class="text-xs text-stone-600 tabular-nums" data-testid="method-bias">偏り {{ formatBias(card.bias) }}{{ card.bias === null ? "" : `（${biasLabel(card.bias)}）` }}</span>
                        <span class="text-[11px] text-stone-400 tabular-nums">比べたSKU {{ formatCount(card.evaluated) }}件・比べられなかったSKU {{ formatCount(card.skipped) }}件</span>
                    </div>
                </div>
                <div class="flex flex-col gap-0.5 border-t border-stone-200 px-4 py-2.5 text-[11px] text-stone-500" data-testid="notes">
                    <p>
                        外れ率（WAPE）＝ SKU・チャネル・起点ごとの「予測と販売実績の差」の合計 ÷
                        販売実績の合計。小さいほど当たっています。偏りは、予測の合計が販売実績の合計より多い（＋）か少ない（−）かです。
                    </p>
                    <p>
                        すべての方式で予測でき、答え合わせの販売実績を取り込んでいるSKUだけを比べています。販売実績は欠品で売れなかった分を補正していません（在庫の記録が2026年9月16日からのため）。
                        イベントの補正は、登録したイベントの日を平均日販から除き、起点より後のイベントの日に、起点より前に終わった同じ名前のイベントから求めた倍率の候補を掛けています（担当者が入れた倍率は使いません）。
                        季節係数は、担当者が今登録している係数をすべての起点で使っています（係数が当たるかを確かめるためで、起点の時点で登録していたかは問いません）。
                    </p>
                </div>
            </section>

            <section v-if="result" class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="accuracy-items">
                <h2 id="accuracy-items" class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-900">品番ごと</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] border-separate border-spacing-0 text-sm" data-testid="item-table">
                        <thead class="text-xs text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                            <tr>
                                <th scope="col" class="text-left">品番</th>
                                <th scope="col" class="text-right">販売実績</th>
                                <th v-for="method in methods" :key="method.value" scope="col" class="text-right">{{ method.short }}</th>
                                <th scope="col" class="text-right">偏り（今の方式）</th>
                            </tr>
                        </thead>
                        <tbody class="[&_td]:border-b [&_td]:border-stone-100 [&_td]:px-4 [&_td]:py-2 [&_th]:border-b [&_th]:border-stone-100 [&_th]:px-4 [&_th]:py-2">
                            <tr v-for="row in items" :key="row.item_no" data-testid="item-row">
                                <th scope="row" class="text-left font-normal">
                                    <span class="flex items-center gap-1.5 whitespace-nowrap">
                                        <span class="font-mono text-sm font-medium text-stone-900">{{ row.item_no }}</span>
                                        <span v-if="row.brand" class="truncate rounded-full border border-stone-200 px-2 py-px text-[11px] text-stone-600">{{ row.brand }}・{{ row.category }}</span>
                                    </span>
                                </th>
                                <td class="text-right text-stone-700 tabular-nums">{{ formatCount(row.methods[currentMethod].actual) }}</td>
                                <td
                                    v-for="method in methods"
                                    :key="method.value"
                                    class="text-right tabular-nums"
                                    :class="bestMethod(row.methods, methods) === method.value ? 'font-semibold text-stone-900' : 'text-stone-600'"
                                    :title="row.methods[method.value].wape === null ? '販売実績が0のため、外れ率を出せません' : undefined"
                                    :data-best="bestMethod(row.methods, methods) === method.value || undefined"
                                    data-testid="item-wape"
                                >
                                    {{ formatWape(row.methods[method.value].wape) }}
                                </td>
                                <td class="text-right text-stone-600 tabular-nums">{{ formatBias(row.methods[currentMethod].bias) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-stone-200 px-4 py-2.5 text-[11px] text-stone-500">販売実績の多い順です。太字は、その品番でいちばん外れ率の小さい方式です。</p>
            </section>

            <section v-if="result" class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="accuracy-origins">
                <h2 id="accuracy-origins" class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-900">起点ごと</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] border-separate border-spacing-0 text-sm" data-testid="origin-table">
                        <thead class="text-xs text-stone-500 [&_th]:border-b [&_th]:border-stone-200 [&_th]:px-4 [&_th]:py-2 [&_th]:font-medium">
                            <tr>
                                <th scope="col" class="text-left">起点</th>
                                <th scope="col" class="text-left">答え合わせした月</th>
                                <th scope="col" class="text-right">販売実績</th>
                                <th v-for="method in methods" :key="method.value" scope="col" class="text-right">{{ method.short }}</th>
                            </tr>
                        </thead>
                        <tbody class="[&_td]:border-b [&_td]:border-stone-100 [&_td]:px-4 [&_td]:py-2">
                            <tr v-for="row in origins" :key="row.origin" data-testid="origin-row">
                                <td class="text-stone-900 tabular-nums">{{ originLabel(row.origin) }}</td>
                                <td class="text-stone-600 tabular-nums">{{ evaluatedMonths(row.origin, horizon, checkMonthOffset) }}</td>
                                <td class="text-right text-stone-700 tabular-nums">{{ formatCount(row.methods[currentMethod].actual) }}</td>
                                <td
                                    v-for="method in methods"
                                    :key="method.value"
                                    class="text-right tabular-nums"
                                    :class="bestMethod(row.methods, methods) === method.value ? 'font-semibold text-stone-900' : 'text-stone-600'"
                                >
                                    {{ formatWape(row.methods[method.value].wape) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-stone-200 px-4 py-2.5 text-[11px] text-stone-500">
                    外れ率の高い月は、キャンペーンや季節で売れ方が変わった月かもしれません。<RouterLink to="/store-sales" class="underline">店舗売上</RouterLink>で、その月の売上を確かめられます。
                </p>
            </section>
        </template>
    </div>
</template>
