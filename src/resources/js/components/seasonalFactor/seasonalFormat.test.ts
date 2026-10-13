import { describe, expect, it } from "vitest";
import { formatFactor, lowMonths, parseFactor } from "@/components/seasonalFactor/seasonalFormat";

describe("seasonalFormat", () => {
    it("reads a factor between the limits with up to two decimals", () => {
        expect(parseFactor(" 1.25 ", 0.1, 5)).toBe(1.25);
        expect(parseFactor("0.05", 0.1, 5)).toBeNull();
        expect(parseFactor("5.5", 0.1, 5)).toBeNull();
        expect(parseFactor("1.234", 0.1, 5)).toBeNull();
        expect(parseFactor("", 0.1, 5)).toBeNull();
        expect(formatFactor(2)).toBe("2.00");
    });

    it("marks months that sold far less than usual as possible stockouts, but not months before the first sale", () => {
        // ph5099 の2024年：5月は1個、8〜12月は0個
        expect([...lowMonths([6, 11, 40, 48, 1, 104, 90, 0, 0, 0, 0, 0])]).toEqual([4, 7, 8, 9, 10, 11]);
        // 発売前（1〜3月）は含めない。取り込んでいない月（null）も含めない
        expect([...lowMonths([0, 0, 0, 30, 32, 28, 1, 31, null, null, null, null])]).toEqual([6]);
        expect(lowMonths([null, null, 5, 6]).size).toBe(0);
    });
});
