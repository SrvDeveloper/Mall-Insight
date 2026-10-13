import { describe, expect, it } from "vitest";
import { bestMethod, biasLabel, evaluatedMonths, formatBias, formatWape } from "@/components/forecastAccuracy/accuracyFormat";
import type { BacktestMetrics } from "@/types/api";

const metrics = (wape: number | null): BacktestMetrics => ({ evaluated: 1, skipped: 0, actual: 10, forecast: 10, abs_error: 0, wape, bias: 0 });

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
        expect(bestMethod({ base_average: metrics(0.6), recent_30: metrics(0.5), last_year: metrics(0.7) })).toBe("recent_30");
        expect(bestMethod({ base_average: metrics(0.5), recent_30: metrics(0.5), last_year: metrics(null) })).toBe("base_average");
        expect(bestMethod({ base_average: metrics(null), recent_30: metrics(null), last_year: metrics(null) })).toBeNull();
    });

    it("names the months compared for each origin", () => {
        expect(evaluatedMonths("2025-10-01", "month_1", 6)).toBe("2025/10");
        expect(evaluatedMonths("2025-11-01", "months_3", 6)).toBe("2025/11〜2026/1");
        expect(evaluatedMonths("2025-10-01", "check_month", 6)).toBe("2026/4");
    });
});
