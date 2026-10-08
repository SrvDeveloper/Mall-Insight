import { describe, expect, it } from "vitest";
import { allocateByWeights } from "@/components/inboundPlan/autoAllocate";

describe("allocateByWeights", () => {
    it("splits the remaining quantity by the weights and makes the total exact", () => {
        // 100 を 1 : 1 : 1 で分けると 33.3… ずつ。端数の1個は先頭のSKUに足す
        expect([
            ...allocateByWeights(
                100,
                new Map([
                    [1, 1],
                    [2, 1],
                    [3, 1],
                ]),
            ),
        ]).toEqual([
            [1, 34],
            [2, 33],
            [3, 33],
        ]);
        // 910 を 1.75 : 0.5 で分けると 707.8 と 202.2
        expect([
            ...allocateByWeights(
                910,
                new Map([
                    [1, 1.75],
                    [2, 0.5],
                ]),
            ),
        ]).toEqual([
            [1, 708],
            [2, 202],
        ]);
    });

    it("gives the leftover units to the SKUs with the largest fractions", () => {
        // 10 を 0.45 : 0.35 : 0.2 で分けると 4.5・3.5・2.0。端数は 0.5 が2つ → 1個を先のSKUへ（合計10）
        expect([
            ...allocateByWeights(
                10,
                new Map([
                    [1, 0.45],
                    [2, 0.35],
                    [3, 0.2],
                ]),
            ),
        ]).toEqual([
            [1, 5],
            [2, 3],
            [3, 2],
        ]);
    });

    it("skips SKUs without a weight and returns nothing when there is nothing to split", () => {
        expect([
            ...allocateByWeights(
                5,
                new Map([
                    [1, 0],
                    [2, 2],
                ]),
            ),
        ]).toEqual([[2, 5]]);
        expect(allocateByWeights(0, new Map([[1, 1]])).size).toBe(0);
        expect(allocateByWeights(10, new Map([[1, 0]])).size).toBe(0);
    });
});
