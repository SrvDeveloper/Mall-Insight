import { describe, expect, it } from "vitest";
import { arcPath, donutSegments, swatchStyle } from "@/components/actuals/rankingDonut";

const items = (count: number) => Array.from({ length: count }, (_, index) => ({ key: `i-${index + 1}`, label: `i-${index + 1}`, value: 120 - index * 10 }));

describe("rankingDonut", () => {
    it("shows the top ten items and folds the rest into others, keeping unregistered SKUs apart", () => {
        // 上位12品番（120+110+…+10 = 780）と未登録 20、合計 800
        const segments = donutSegments(items(12), 20, 800);

        expect(segments.map((segment) => segment.label)).toEqual(["i-1", "i-2", "i-3", "i-4", "i-5", "i-6", "i-7", "i-8", "i-9", "i-10", "その他の品番", "未登録のSKU"]);
        // その他は 11・12位（20 + 10）
        expect(segments[10]!.value).toBe(30);
        expect(segments.map((segment) => segment.share).reduce((total, share) => total + share, 0)).toBeCloseTo(1);
        expect(segments.slice(10).every((segment) => segment.isGroup)).toBe(true);
    });

    it("uses seven hues without red, then the first three hues with lines for ranks eight to ten", () => {
        const segments = donutSegments(items(10), 0, 750);

        expect(segments.slice(0, 7).map((segment) => [segment.color, segment.ink])).toEqual([
            ["#2a78d6", null],
            ["#eb6834", null],
            ["#1baf7a", null],
            ["#eda100", null],
            ["#e87ba4", null],
            ["#008300", null],
            ["#4a3aa7", null],
        ]);
        expect(segments.slice(7).map((segment) => segment.color)).toEqual(["#2a78d6", "#eb6834", "#1baf7a"]);
        expect(segments.slice(7).every((segment) => segment.ink !== null)).toBe(true);
        expect(swatchStyle(segments[7]!).backgroundImage).toContain("repeating-linear-gradient(45deg");
        expect(swatchStyle(segments[0]!).backgroundImage).toBeUndefined();
    });

    it("omits others when every item is shown", () => {
        expect(donutSegments(items(2), 0, 230).map((segment) => segment.label)).toEqual(["i-1", "i-2"]);
        expect(donutSegments([], 0, 0)).toEqual([]);
    });

    it("draws a whole ring for a single segment and a large arc past half", () => {
        expect(arcPath(0, 1)).toContain("Z M");
        expect(arcPath(0, 0.75)).toContain(" 0 1 1 ");
        expect(arcPath(0, 0.25)).toContain(" 0 0 1 ");
    });
});
