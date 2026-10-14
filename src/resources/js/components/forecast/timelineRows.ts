import { chartFormats } from "@/components/actuals/actualRows";
import type { ForecastTimelineColumn, ForecastTimelineResponse, ForecastTimelineSku, SalesUnit } from "@/types/api";

/**
 * 需要予測の画面（B-131、K-123）の計算。SKU・チャネルごとの値を、表示しているモール（全体・BOSS・Amazon）でまとめ、
 * 過去の月・日の販売実績と予測し直した数の差異を求める。
 *
 * - 差異＝（予測し直した数－販売実績）÷販売実績。販売実績と予測し直した数がどちらもあるSKU・チャネルだけで比べる。
 *   日ごとは、欠品した日のSKU・チャネルを比べない（欠品で売れなかった分は外れとしない、K-123）。
 * - 外れ率＝月・日ごとの差異の大きさ（|予測し直した数－販売実績|）の合計÷販売実績の合計。偏り＝（予測し直した数の合計－販売実績の合計）÷販売実績の合計。
 * - 金額は、販売実績は取り込んだ金額（税込）、予測はSKUのチャネルの単価を掛けた概算（B-123）。単価を出せないSKUは金額に入れない。
 * - 欠品率＝SKU・チャネルごとの欠品した日数の合計÷在庫の記録で欠品かを判定できた日数の合計。日ごとは、欠品したSKUの割合になる。
 */

export type ForecastScope = "total" | "boss" | "amazon";
export type ForecastMeasure = "quantity" | "amount";
export type ForecastChannel = "boss" | "amazon";

export const CHANNELS: ForecastChannel[] = ["boss", "amazon"];

/** 差異を強調する大きさ（K-123）。 */
export const VARIANCE_THRESHOLDS: Record<SalesUnit, number> = { month: 0.3, day: 0.5 };

/** 月・日の欠品（SKU・チャネルごとに数える）。 */
export interface StockoutFigure {
    /** 欠品したSKU（日ごとはその日に欠品した、月ごとは欠品した日がある） */
    skus: number;
    /** 欠品かを判定できたSKU */
    knownSkus: number;
    /** 欠品した日数の合計（日ごとは欠品したSKUの数と同じ） */
    days: number;
    /** 欠品かを判定できた日数の合計 */
    knownDays: number;
}

/** 欠品率（判定できた日が無ければ null）。 */
export const stockoutRate = (stockout: StockoutFigure | undefined): number | null => (stockout && stockout.knownDays > 0 ? stockout.days / stockout.knownDays : null);

const emptyStockouts = (length: number): StockoutFigure[] => Array.from({ length }, () => ({ skus: 0, knownSkus: 0, days: 0, knownDays: 0 }));

export const scopeChannels = (scope: ForecastScope): ForecastChannel[] => (scope === "total" ? CHANNELS : [scope]);

/** 表示しているモールでまとめた、月・日ごとの値（columns の順）。 */
export interface TimelineFigures {
    /** チャネルごとの販売実績と需要予測（積み上げに使う）と、需要予測のうちイベントで増やした数。そのチャネルで売っているSKUが無ければ持たない */
    channels: Partial<Record<ForecastChannel, { actual: (number | null)[]; forecast: (number | null)[]; event: (number | null)[]; stockouts: StockoutFigure[] }>>;
    actual: (number | null)[];
    /** 予測し直した数（予測できたSKU・チャネルの合計） */
    replayed: (number | null)[];
    forecast: (number | null)[];
    /** 差異（比べられなければ null） */
    variance: (number | null)[];
    /** 欠品で比べられない月・日か（欠品したSKU・チャネルがあり、ほかに比べられるSKU・チャネルが無い） */
    stockout: boolean[];
    /** 欠品（表示しているモールのチャネルの合計） */
    stockouts: StockoutFigure[];
    /** 過去の期間の外れ率と偏り（比べられなければ null） */
    wape: number | null;
    bias: number | null;
    /** 今月（今日）から先の需要予測の合計 */
    forecastTotal: number;
    /** 過去の期間の販売実績の合計 */
    pastActualTotal: number;
    /** 基準平均日販（数量、予測できたSKU・チャネルの合計。どれも予測できなければ null） */
    baseAverage: number | null;
}

function add(list: (number | null)[], index: number, value: number): void {
    list[index] = (list[index] ?? 0) + value;
}

/** SKUの、チャネルの値を数量か金額にする倍率（金額は単価。単価を出せなければ null）。 */
function priceOf(sku: ForecastTimelineSku, channel: ForecastChannel, measure: ForecastMeasure): number | null {
    return measure === "quantity" ? 1 : sku.unit_prices[channel];
}

/** SKU（品番ならそのSKUすべて）の値を、表示しているモールでまとめる。 */
export function timelineFigures(skus: ForecastTimelineSku[], columns: ForecastTimelineColumn[], scope: ForecastScope, measure: ForecastMeasure, unit: SalesUnit): TimelineFigures {
    const length = columns.length;
    const empty = (): (number | null)[] => Array.from({ length }, () => null);
    const figures: TimelineFigures = {
        channels: {},
        actual: empty(),
        replayed: empty(),
        forecast: empty(),
        variance: empty(),
        stockout: Array.from({ length }, () => false),
        stockouts: emptyStockouts(length),
        wape: null,
        bias: null,
        forecastTotal: 0,
        pastActualTotal: 0,
        baseAverage: null,
    };
    const comparedActual = Array.from({ length }, () => 0);
    const comparedReplayed = Array.from({ length }, () => 0);
    const compared = Array.from({ length }, () => false);

    for (const channel of scopeChannels(scope)) {
        const values = skus.filter((sku) => sku.channels[channel]);
        if (values.length === 0) {
            continue;
        }
        const series = { actual: empty(), forecast: empty(), event: empty(), stockouts: emptyStockouts(length) };
        for (const sku of values) {
            const data = sku.channels[channel]!;
            if (data.base_average !== null) {
                figures.baseAverage = (figures.baseAverage ?? 0) + data.base_average;
            }
            const price = priceOf(sku, channel, measure);
            columns.forEach((column, index) => {
                const actual = measure === "amount" ? data.actual_amount[index] : data.actual[index];
                const stockout = data.stockout[index];
                const known = data.stockout_known[index] ?? null;
                if (stockout !== null && stockout !== undefined && known !== null && known > 0) {
                    const days = stockout === true ? 1 : stockout === false ? 0 : stockout;
                    for (const target of [figures.stockouts[index]!, series.stockouts[index]!]) {
                        target.skus += days > 0 ? 1 : 0;
                        target.knownSkus += 1;
                        target.days += days;
                        target.knownDays += known;
                    }
                }
                if (actual !== null && actual !== undefined) {
                    add(series.actual, index, actual);
                    add(figures.actual, index, actual);
                }
                if (price === null) {
                    return;
                }
                const forecast = data.forecast[index];
                if (forecast !== null && forecast !== undefined) {
                    add(series.forecast, index, forecast * price);
                    add(figures.forecast, index, forecast * price);
                }
                const event = data.event[index];
                if (event !== null && event !== undefined) {
                    add(series.event, index, event * price);
                }
                const replayed = data.replayed[index];
                if (replayed !== null && replayed !== undefined) {
                    add(figures.replayed, index, replayed * price);
                    // 販売実績もある日・月だけ比べる。日ごとは欠品した日を比べない
                    if (actual !== null && actual !== undefined && !(unit === "day" && stockout === true)) {
                        comparedActual[index]! += actual;
                        comparedReplayed[index]! += replayed * price;
                        compared[index] = true;
                    }
                }
            });
        }
        figures.channels[channel] = series;
    }

    let errorTotal = 0;
    let actualTotal = 0;
    let replayedTotal = 0;
    columns.forEach((column, index) => {
        figures.stockout[index] = figures.stockouts[index]!.skus > 0 && !compared[index];
        if (column.kind === "past") {
            figures.pastActualTotal += figures.actual[index] ?? 0;
            if (compared[index]) {
                errorTotal += Math.abs(comparedReplayed[index]! - comparedActual[index]!);
                actualTotal += comparedActual[index]!;
                replayedTotal += comparedReplayed[index]!;
                figures.variance[index] = comparedActual[index]! > 0 ? (comparedReplayed[index]! - comparedActual[index]!) / comparedActual[index]! : null;
            }
        } else {
            figures.forecastTotal += figures.forecast[index] ?? 0;
        }
    });
    if (actualTotal > 0) {
        figures.wape = errorTotal / actualTotal;
        figures.bias = (replayedTotal - actualTotal) / actualTotal;
    }

    return figures;
}

/** 品番ごとの値（予測の多い順に並べ替えるのは呼び出し側）。 */
export interface TimelineItem {
    itemNo: string;
    brand: string;
    category: string;
    skuCount: number;
    /** 予測できないSKUの数 */
    unpredictableCount: number;
    figures: TimelineFigures;
    /** SKUごとの値（表のSKUの行、SKU別の画面） */
    skus: { sku: ForecastTimelineSku; figures: TimelineFigures }[];
}

/** 品番ごとの値。全体・Amazon・BOSS の対象品番（B-136、K-129）に入っている品番だけを出す。 */
export function timelineItems(response: ForecastTimelineResponse, scope: ForecastScope, measure: ForecastMeasure): TimelineItem[] {
    const { columns, unit } = response.meta;
    const targets = response.meta.target_items?.[scope];
    return response.data
        .filter((item) => targets === undefined || targets.includes(item.item_no))
        .map((item) => ({
            itemNo: item.item_no,
            brand: item.brand,
            category: item.category,
            skuCount: item.skus.length,
            unpredictableCount: item.skus.filter((sku) => sku.unpredictable_reason_label !== null).length,
            figures: timelineFigures(item.skus, columns, scope, measure, unit),
            skus: item.skus.map((sku) => ({ sku, figures: timelineFigures([sku], columns, scope, measure, unit) })),
        }));
}

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];

/** 月・日の見出し。月ごとは「10月」と1月（と最初の月）の年、日ごとは「10/13」と曜日。 */
export interface TimelineLabel {
    label: string;
    sub: string;
    /** 土日（日ごと） */
    weekend: boolean;
    /** 長い見出し（ツールチップ）。「2026年10月」「10月13日（火）」 */
    title: string;
}

export function timelineLabels(columns: ForecastTimelineColumn[], unit: SalesUnit): TimelineLabel[] {
    return columns.map((column, index) => {
        if (unit === "day") {
            const [year, month, day] = column.key.split("-").map(Number) as [number, number, number];
            const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
            return { label: `${month}/${day}`, sub: WEEKDAYS[weekday]!, weekend: weekday === 0 || weekday === 6, title: `${month}月${day}日（${WEEKDAYS[weekday]}）` };
        }
        const [year, month] = column.key.split("-").map(Number) as [number, number];
        return { label: `${month}月`, sub: month === 1 || index === 0 ? String(year) : "", weekend: false, title: `${year}年${month}月` };
    });
}

/** 月・日に重なるイベントの期間（表示しているモールのチャネルのもの）。 */
export function columnEvents(response: ForecastTimelineResponse, scope: ForecastScope): ForecastTimelineResponse["meta"]["events"][] {
    const channels = scopeChannels(scope);
    const events = response.meta.events.filter((event) => event.channels.some((channel) => channels.includes(channel)));
    return response.meta.columns.map((column) => {
        const [start, end] = response.meta.unit === "day" ? [column.key, column.key] : [`${column.key}-01`, `${column.key}-31`];
        return events.filter((event) => event.starts_on <= end && event.ends_on >= start);
    });
}

const percentFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });

/** 「+12%」「−8%」。 */
export const formatVariance = (value: number | null): string => (value === null ? "" : `${value >= 0 ? "+" : "−"}${percentFormat.format(Math.abs(value * 100))}%`);

/** 「28.4%」。 */
export const formatRate = (value: number | null, digits = 1): string => (value === null ? "—" : `${(value * 100).toFixed(digits)}%`);

const integerFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });
const decimalFormat = new Intl.NumberFormat("ja-JP", { minimumFractionDigits: 1, maximumFractionDigits: 1 });

/**
 * 表の数。数量は個（日ごとの10個未満は小数1桁。0.4個を0と見せないため）、金額は千円。値が無ければ「—」。
 */
export function formatFigure(value: number | null | undefined, measure: ForecastMeasure, unit: SalesUnit): string {
    if (value === null || value === undefined) {
        return "—";
    }
    if (measure === "amount") {
        return integerFormat.format(Math.round(value / 1000));
    }
    return unit === "day" && Math.abs(value) < 10 && Math.round(value) !== value ? decimalFormat.format(value) : integerFormat.format(Math.round(value));
}

const axisFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 2 });

/**
 * グラフの数の書き方。数量は表と同じく、日ごとの10個未満は小数1桁にする（1日0.4個の予測を「0個」と見せないため）。
 * 縦軸の目盛りも、1個未満の刻み（0.25個など）を丸めずに出す。金額は品番別売上のグラフと同じ。
 */
export function forecastChartFormats(measure: ForecastMeasure, unit: SalesUnit): ReturnType<typeof chartFormats> {
    if (measure === "amount") {
        return chartFormats(measure);
    }
    return { formatValue: (value) => `${formatFigure(value, measure, unit)}個`, formatAxis: (value) => axisFormat.format(value), axisUnit: "個" };
}
