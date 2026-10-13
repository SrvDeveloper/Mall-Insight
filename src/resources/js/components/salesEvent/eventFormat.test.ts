import { describe, expect, it } from "vitest";
import { daysBetween, eventNames, formatChange, formatMultiplier, parseMultiplier, periodLabel } from "@/components/salesEvent/eventFormat";

describe("eventFormat", () => {
    it("names the period, adding the year to the end only when it changes", () => {
        expect(periodLabel("2026-12-04", "2026-12-11")).toBe("2026/12/4〜12/11");
        expect(periodLabel("2026-12-28", "2027-01-03")).toBe("2026/12/28〜2027/1/3");
        expect(periodLabel("2026-11-11", "2026-11-11")).toBe("2026/11/11");
        expect(daysBetween("2026-12-04", "2026-12-11")).toBe(8);
        expect(daysBetween("2026-12-04", "")).toBeNull();
    });

    it("reads a multiplier of up to two decimals, treating a blank as the candidate", () => {
        expect(parseMultiplier(" 3.5 ")).toBe(3.5);
        expect(parseMultiplier("")).toBeNull();
        expect(parseMultiplier("2.125")).toBeUndefined();
        expect(parseMultiplier("-1")).toBeUndefined();
        expect(parseMultiplier("51")).toBeUndefined();
        expect(formatMultiplier(4.6667)).toBe("4.67倍");
        expect(formatMultiplier(null)).toBe("—");
        // 倍率が1未満なら、イベントで減る
        expect([formatChange(40.2), formatChange(-1.26), formatChange(0.3), formatChange(null)]).toEqual(["+40", "−1", "0", "—"]);
    });

    it("lists the names already used once each", () => {
        expect(eventNames([{ name: "楽天スーパーセール" }, { name: "プライムデー" }, { name: "楽天スーパーセール" }])).toEqual(["楽天スーパーセール", "プライムデー"]);
    });
});
