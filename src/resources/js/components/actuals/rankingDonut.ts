/**
 * 売上上位の円グラフ（ドーナツ）の区分（K-097）。円グラフで割合を見分けられるのは6区分までのため、上位の品番に「その他の品番」と
 * 「未登録のSKU」（CrossWalkerに未登録のSKU、あるときだけ）を足して6区分にまとめる。上位の品番の色はグラフの指針の検証済みの並び
 * （青・橙・水色・黄・桃）を順位の順に使い、まとめた区分は灰色にする。
 */

export interface DonutSegment {
    key: string;
    label: string;
    value: number;
    share: number;
    color: string;
    /** その他・未登録のようにまとめた区分 */
    isGroup: boolean;
}

export const DONUT_COLORS = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4"];
const OTHER_COLOR = "#a8a29e";
const UNREGISTERED_COLOR = "#d6d3d1";
const MAX_SEGMENTS = 6;

/**
 * @param items 順位の順の品番（値は並び順で見ている金額か販売数）
 * @param unregistered 未登録のSKUの値
 * @param total 店舗・期間の合計
 */
export function donutSegments(items: { key: string; label: string; value: number }[], unregistered: number, total: number): DonutSegment[] {
    if (total <= 0) {
        return [];
    }
    const hasUnregistered = unregistered > 0;
    const shown = Math.min(items.length, MAX_SEGMENTS - 1 - (hasUnregistered ? 1 : 0));
    const top = items.slice(0, shown);
    const other = total - unregistered - top.reduce((sum, item) => sum + item.value, 0);
    const segments: DonutSegment[] = top.map((item, index) => ({ ...item, share: item.value / total, color: DONUT_COLORS[index]!, isGroup: false }));
    // 表示する件数より品番が少なければ「その他」は0になるので出さない
    if (other > 0.5) {
        segments.push({ key: "other", label: "その他の品番", value: other, share: other / total, color: OTHER_COLOR, isGroup: true });
    }
    if (hasUnregistered) {
        segments.push({ key: "unregistered", label: "未登録のSKU", value: unregistered, share: unregistered / total, color: UNREGISTERED_COLOR, isGroup: true });
    }
    return segments;
}

/** 区分の扇形（中心 100,100、外側の半径 r1・内側の半径 r0、start～end は0～1）。1区分だけなら輪全体。 */
export function arcPath(start: number, end: number, r1 = 92, r0 = 60): string {
    if (end - start >= 0.9999) {
        return `M 100 ${100 - r1} A ${r1} ${r1} 0 1 1 99.99 ${100 - r1} Z M 100 ${100 - r0} A ${r0} ${r0} 0 1 0 100.01 ${100 - r0} Z`;
    }
    const point = (ratio: number, r: number): string => {
        const angle = ratio * 2 * Math.PI - Math.PI / 2;
        return `${(100 + r * Math.cos(angle)).toFixed(2)} ${(100 + r * Math.sin(angle)).toFixed(2)}`;
    };
    const large = end - start > 0.5 ? 1 : 0;
    return `M ${point(start, r1)} A ${r1} ${r1} 0 ${large} 1 ${point(end, r1)} L ${point(end, r0)} A ${r0} ${r0} 0 ${large} 0 ${point(start, r0)} Z`;
}
