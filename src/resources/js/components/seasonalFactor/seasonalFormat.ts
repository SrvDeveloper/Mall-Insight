/**
 * 季節係数（B-106、K-113）の表示と入力の整え方。係数は1月～12月の順に持つ。
 */

export const MONTH_LABELS = ["1月", "2月", "3月", "4月", "5月", "6月", "7月", "8月", "9月", "10月", "11月", "12月"];

export const SOURCE_LABELS = { item: "品番の実績", category: "カテゴリーの実績", overall: "対象品番全体の実績" } as const;

/** 入力した係数を読む。読めない値・範囲外・小数3桁以上は null。 */
export function parseFactor(input: string, min: number, max: number): number | null {
    const text = input.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return null;
    }
    const value = Number(text);
    return value >= min && value <= max ? value : null;
}

export const formatFactor = (value: number | null): string => (value === null ? "—" : value.toFixed(2));

/**
 * 欠品していた可能性のある月（その年の、販売のある月の中央値の2割に満たない月）。発売前（その年でまだ1個も売れていない月）は含めない。
 * 在庫の記録が無い期間の欠品を見分ける手がかりにする。
 *
 * @return その年の月の位置（0〜11）
 */
export function lowMonths(months: (number | null)[]): Set<number> {
    const sold = months.filter((value): value is number => value !== null && value > 0).sort((a, b) => a - b);
    if (sold.length < 3) {
        return new Set();
    }
    const middle = Math.floor(sold.length / 2);
    const median = sold.length % 2 === 1 ? sold[middle]! : (sold[middle - 1]! + sold[middle]!) / 2;
    const firstSold = months.findIndex((value) => value !== null && value > 0);
    const low = new Set<number>();
    months.forEach((value, index) => {
        if (value !== null && index > firstSold && value < median * 0.2) {
            low.add(index);
        }
    });
    return low;
}
