import { computed, onMounted, ref, shallowRef, watch, type Ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchForecastTimeline } from "@/api/demandForecasts";
import { VARIANCE_THRESHOLDS, columnEvents, timelineItems, timelineLabels, type ForecastMeasure, type ForecastScope } from "@/components/forecast/timelineRows";
import type { ForecastTimelineResponse, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面と SKU別の需要予測（B-131、K-123）で共通の、URL に持たせる選択（モール・月ごとと日ごと・数量と金額・
 * 日ごとの期間）と、並びの読み込み。需要予測の画面から SKU別の画面へ移っても、同じ選択のまま見られるようにする。
 * 期間は月ごとと日ごとで形が違うため、月ごと・日ごとを切り替えたら初期値に戻す。
 *
 * @param itemNo SKU別の需要予測で見る品番。需要予測の画面では null
 */
export function useForecastTimeline(itemNo: Ref<string | null>) {
    const route = useRoute();
    const router = useRouter();

    const scope = computed<ForecastScope>(() => (route.query.scope === "amazon" || route.query.scope === "boss" ? route.query.scope : "total"));
    const unit = computed<SalesUnit>(() => (route.query.unit === "day" ? "day" : "month"));
    const measure = computed<ForecastMeasure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));
    const period = computed(() => (unit.value === "day" && typeof route.query.from === "string" && typeof route.query.to === "string" ? { from: route.query.from, to: route.query.to } : null));

    function updateQuery(changes: Record<string, string | undefined>): void {
        void router.replace({ query: { ...route.query, ...changes } });
    }

    function selectUnit(next: SalesUnit): void {
        updateQuery({ unit: next === "month" ? undefined : next, from: undefined, to: undefined, past: undefined });
    }

    const result = shallowRef<ForecastTimelineResponse | null>(null);
    const isLoading = ref(false);
    const hasError = ref(false);
    /** 期間の指定が受け付けられなかったときの理由（前の結果は表示したままにする） */
    const periodError = ref<string | null>(null);
    const requestKey = computed(() => JSON.stringify([unit.value, period.value, itemNo.value]));

    async function load(): Promise<void> {
        const key = requestKey.value;
        isLoading.value = true;
        hasError.value = false;
        periodError.value = null;
        try {
            const response = await fetchForecastTimeline({ unit: unit.value, period: period.value, itemNo: itemNo.value });
            // 読み込み中に選択を変えたときは、古い方の結果を表示しない
            if (key === requestKey.value) {
                result.value = response;
            }
        } catch (error) {
            if (error instanceof ApiError && error.isValidationError && (error.errors.from || error.errors.to)) {
                periodError.value = error.errors.to?.[0] ?? error.errors.from?.[0] ?? null;
            } else if (key === requestKey.value) {
                hasError.value = true;
            }
        } finally {
            if (key === requestKey.value) {
                isLoading.value = false;
            }
        }
    }

    onMounted(load);
    watch(requestKey, (current, previous) => {
        // 月ごと・日ごとや品番を変えたときは、前の結果を消す（列の形や中身が違うため）
        const [previousUnit, , previousItem] = JSON.parse(previous) as unknown[];
        const [currentUnit, , currentItem] = JSON.parse(current) as unknown[];
        if (previousUnit !== currentUnit || previousItem !== currentItem) {
            result.value = null;
        }
        void load();
    });

    const threshold = computed(() => VARIANCE_THRESHOLDS[unit.value]);
    const items = computed(() => (result.value ? timelineItems(result.value, scope.value, measure.value) : []));
    const labels = computed(() => (result.value ? timelineLabels(result.value.meta.columns, result.value.meta.unit) : []));
    const events = computed(() => (result.value ? columnEvents(result.value, scope.value) : []));

    return { scope, unit, measure, updateQuery, selectUnit, result, isLoading, hasError, periodError, load, threshold, items, labels, events };
}
