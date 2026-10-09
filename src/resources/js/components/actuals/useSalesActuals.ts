import { computed, onMounted, ref, shallowRef, watch, type Ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ApiError } from "@/api/client";
import { fetchSalesActuals, type SalesPeriod } from "@/api/actuals";
import { buildActualRows, salesColumns, type ActualColumn, type ActualItemRows, type ActualMeasure } from "@/components/actuals/actualRows";
import type { SalesActualsResponse, SalesUnit, TrendScope } from "@/types/api";

/**
 * 品番別売上・SKU別売上（B-124・B-127）で共通の、URL に持たせる選択（モール・月ごとと日ごと・数量と金額・見せ方・期間）と、販売実績の読み込み。
 * 品番別売上からSKU別売上へ移っても、同じ選択のまま見られるようにする。期間（from・to）は月ごとと日ごとで形が違うため、
 * 月ごと・日ごとを切り替えたら初期値に戻す（K-080）。
 *
 * @param itemNo SKU別売上で見る品番。品番別売上では null
 */

export type SalesView = "table" | "graph";

export function useSalesActuals(itemNo: Ref<string | null>) {
    const route = useRoute();
    const router = useRouter();

    const scope = computed<TrendScope>(() => (route.query.scope === "amazon" || route.query.scope === "boss" ? route.query.scope : "total"));
    const unit = computed<SalesUnit>(() => (route.query.unit === "day" ? "day" : "month"));
    const measure = computed<ActualMeasure>(() => (route.query.measure === "amount" ? "amount" : "quantity"));
    const view = computed<SalesView>(() => (route.query.view === "graph" ? "graph" : "table"));
    const period = computed<SalesPeriod>(() => (typeof route.query.from === "string" && typeof route.query.to === "string" ? { from: route.query.from, to: route.query.to } : null));

    function updateQuery(changes: { scope?: TrendScope; unit?: SalesUnit; measure?: ActualMeasure; view?: SalesView; period?: SalesPeriod }): void {
        const next = { scope: changes.scope ?? scope.value, unit: changes.unit ?? unit.value, measure: changes.measure ?? measure.value, view: changes.view ?? view.value };
        const nextPeriod = changes.unit && changes.unit !== unit.value ? null : changes.period !== undefined ? changes.period : period.value;
        void router.replace({
            query: {
                ...route.query,
                scope: next.scope === "total" ? undefined : next.scope,
                unit: next.unit === "month" ? undefined : next.unit,
                measure: next.measure === "quantity" ? undefined : next.measure,
                view: next.view === "table" ? undefined : next.view,
                from: nextPeriod?.from,
                to: nextPeriod?.to,
            },
        });
    }

    const result = shallowRef<SalesActualsResponse | null>(null);
    const isLoading = ref(false);
    const hasError = ref(false);
    /** 期間の指定が受け付けられなかったときの理由（前の結果は表示したままにする） */
    const periodError = ref<string | null>(null);

    const requestKey = computed(() => JSON.stringify([scope.value, unit.value, itemNo.value, period.value]));

    async function load(): Promise<void> {
        const key = requestKey.value;
        isLoading.value = true;
        hasError.value = false;
        periodError.value = null;
        try {
            const response = await fetchSalesActuals(scope.value, unit.value, itemNo.value, period.value);
            // 読み込み中に選択を変えたときは、古い方の結果を表示しない
            if (key === requestKey.value) {
                result.value = response;
            }
        } catch (error) {
            if (error instanceof ApiError && error.isValidationError && (error.errors.from || error.errors.to)) {
                periodError.value = error.errors.from?.[0] ?? error.errors.to?.[0] ?? null;
            } else {
                hasError.value = true;
            }
        } finally {
            isLoading.value = false;
        }
    }

    onMounted(load);
    watch(requestKey, (current, previous) => {
        // 期間だけを変えたときは、結果を消さずに読み込み直す（期間の欄が消えないように）
        const [, , , previousPeriod] = JSON.parse(previous) as unknown[];
        const [, , , currentPeriod] = JSON.parse(current) as unknown[];
        if (JSON.stringify(previousPeriod) === JSON.stringify(currentPeriod)) {
            result.value = null;
        }
        void load();
    });

    const columns = computed<ActualColumn[]>(() => (result.value ? salesColumns(result.value.meta) : []));
    const itemRows = computed<ActualItemRows[]>(() => buildActualRows(result.value?.data ?? [], "sales", measure.value));
    const summaryLabel = computed(() => `期間計${measure.value === "amount" ? "（千円）" : ""}`);
    const notSoldLabel = computed(() => `${result.value?.meta.scope_label ?? ""}では販売していません`);

    const formatDate = (value: string): string => value.replaceAll("-", "/");

    /** 販売実績を取り込んだ期間（選んだモール）。 */
    const rangeNote = computed(() =>
        (result.value?.meta.channels ?? [])
            .filter((channel) => scope.value === "total" || channel.channel === scope.value)
            .map((channel) => (channel.sales_data_from ? `${channel.label} ${formatDate(channel.sales_data_from)}〜${formatDate(channel.sales_data_to!)}` : `${channel.label} 未取込`))
            .join("・"),
    );

    return { scope, unit, measure, view, period, updateQuery, result, isLoading, hasError, periodError, load, columns, itemRows, summaryLabel, notSoldLabel, rangeNote };
}
