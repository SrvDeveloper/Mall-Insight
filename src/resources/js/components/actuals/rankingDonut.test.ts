import { describe, expect, it } from "vitest";
import { arcPath, donutSegments } from "@/components/actuals/rankingDonut";

const items = (count: number) => Array.from({ length: count }, (_, index) => ({ key: `i-${index + 1}`, label: `i-${index + 1}`, value: 100 - index * 10 }));

describe("rankingDonut", () => {
    it("folds the items below the top four into others, keeping unregistered SKUs apart, in six segments at most", () => {
        // 上位6品番（100+90+80+70+60+50 = 450）と未登録 50、合計 500
        const segments = donutSegments(items(6), 50, 500);

        expect(segments.map((segment) => [segment.label, segment.value])).toEqual([
            ["i-1", 100],
            ["i-2", 90],
            ["i-3", 80],
            ["i-4", 70],
            ["その他の品番", 110],
            ["未登録のSKU", 50],
        ]);
        expect(segments.map((segment) => segment.share).reduce((total, share) => total + share, 0)).toBeCloseTo(1);
        expect(segments[0]!.color).toBe("#2a78d6");
        expect(segments.slice(4).every((segment) => segment.isGroup)).toBe(true);
    });

    it("shows the top five items when there are no unregistered SKUs, and omits empty others", () => {
        expect(donutSegments(items(6), 0, 450).map((segment) => segment.label)).toEqual(["i-1", "i-2", "i-3", "i-4", "i-5", "その他の品番"]);
        expect(donutSegments(items(2), 0, 190).map((segment) => segment.label)).toEqual(["i-1", "i-2"]);
        expect(donutSegments([], 0, 0)).toEqual([]);
    });

    it("draws a whole ring for a single segment and a large arc past half", () => {
        expect(arcPath(0, 1)).toContain("Z M");
        expect(arcPath(0, 0.75)).toContain(" 0 1 1 ");
        expect(arcPath(0, 0.25)).toContain(" 0 0 1 ");
    });
});
