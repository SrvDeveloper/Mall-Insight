import { describe, expect, it } from "vitest";
import { bestMethod, biasLabel, evaluatedMonths, formatBias, formatWape, measuredMethods } from "@/components/forecastAccuracy/accuracyFormat";
import type { BacktestMethod, BacktestMetrics } from "@/types/api";

const metrics = (wape: number | null): BacktestMetrics => ({ evaluated: 1, skipped: 0, actual: 10, forecast: 10, abs_error: 0, wape, bias: 0, item_wape: wape, event: null, normal: null });

describe("accuracyFormat", () => {
    it("shows the error rate and the bias as percentages, or a dash without sales", () => {
        expect(formatWape(0.6312)).toBe("63.1%");
        expect(formatWape(null)).toBe("—");
        expect(formatBias(0.071)).toBe("+7.1%");
        expect(formatBias(-0.278)).toBe("−27.8%");
        expect(formatBias(0)).toBe("0.0%");
        expect([biasLabel(0.1), biasLabel(-0.1), biasLabel(0), biasLabel(null)]).toEqual(["多めに予測", "少なめに予測", "偏りなし", ""]);
    });

    it("picks the method with the smallest error rate, preferring the current method on a tie", () => {
        expect(bestMethod({ seasonal: metrics(0.55), event_adjusted: metrics(0.55), base_average: metrics(0.6), recent_30: metrics(0.5), last_year: metrics(0.7) })).toBe("recent_30");
        expect(bestMethod({ seasonal: metrics(0.5), event_adjusted: metrics(0.5), base_average: metrics(0.5), recent_30: metrics(0.5), last_year: metrics(null) })).toBe("seasonal");
        // イベントの補正より前に測った記録には、今の方式が無い
        expect(bestMethod({ base_average: metrics(0.5), recent_30: metrics(0.6), last_year: metrics(null) })).toBe("base_average");
        expect(bestMethod({ seasonal: metrics(null), event_adjusted: metrics(null), base_average: metrics(null), recent_30: metrics(null), last_year: metrics(null) })).toBeNull();
    });

    it("leaves out methods that the run did not measure", () => {
        const card = (method: BacktestMethod, evaluated: number) => ({ method, evaluated, skipped: 0 });
        const cards = (...evaluated: number[]) =>
            (["seasonal", "event_adjusted", "base_average", "recent_30", "last_year"] as BacktestMethod[]).map((method, index) => card(method, evaluated[index]!));
        // 季節係数・イベントの補正より前に測った記録
        expect(measuredMethods(cards(0, 0, 3, 3, 3)).map((method) => method.value)).toEqual(["base_average", "recent_30", "last_year"]);
        // 季節係数より前に測った記録は、イベントの補正だけが今の方式
        expect(measuredMethods(cards(0, 3, 3, 3, 3))[0]!.value).toBe("event_adjusted");
        expect(measuredMethods(cards(3, 3, 3, 3, 3))).toHaveLength(5);
        expect(measuredMethods(cards(0, 0, 0, 0, 0))).toHaveLength(5);
    });

    it("names the months compared for each origin", () => {
        expect(evaluatedMonths("2025-10-01", "month_1", 6)).toBe("2025/10");
        expect(evaluatedMonths("2025-11-01", "months_3", 6)).toBe("2025/11〜2026/1");
        expect(evaluatedMonths("2025-10-01", "check_month", 6)).toBe("2026/4");
    });
});
