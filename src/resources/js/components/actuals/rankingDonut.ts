/**
 * 売上上位の円グラフ（ドーナツ）の区分（K-097・K-098）。上位10品番に「その他の品番」と「未登録のSKU」（CrossWalkerに未登録のSKU、
 * あるときだけ）を足す。上位の品番の色は、グラフの指針の検証済みの並びのうち赤（在庫不足・欠品に使う、K-074）を除く7色
 * （青・橙・水色・黄・桃・緑・紫）を順位の順に使う。8～10位は色を作り足さず、1～3位の色に同じ色の濃い斜線（45°）を重ねて見分ける
 * （色と模様の組み合わせ）。まとめた区分は灰色にする。
 */

export interface DonutSegment {
    key: string;
    label: string;
    value: number;
    share: number;
    color: string;
    /** 斜線の色（8～10位だけ。同じ色の濃い色） */
    ink: string | null;
    /** その他・未登録のようにまとめた区分 */
    isGroup: boolean;
}

export const DONUT_COLORS = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4", "#008300", "#4a3aa7"];
/** 8～10位に重ねる斜線の色（1～3位の色の濃い色） */
const DONUT_INKS = ["#174a8c", "#a8441c", "#0f6b4a"];
const OTHER_COLOR = "#a8a29e";
const UNREGISTERED_COLOR = "#d6d3d1";
/** 円グラフに出す品番の数 */
export const DONUT_ITEMS = 10;

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
    const top = items.slice(0, DONUT_ITEMS);
    const other = total - unregistered - top.reduce((sum, item) => sum + item.value, 0);
    const segments: DonutSegment[] = top.map((item, index) => ({
        ...item,
        share: item.value / total,
        color: DONUT_COLORS[index % DONUT_COLORS.length]!,
        ink: index < DONUT_COLORS.length ? null : DONUT_INKS[index - DONUT_COLORS.length]!,
        isGroup: false,
    }));
    // 表示する件数より品番が少なければ「その他」は0になるので出さない
    if (other > 0.5) {
        segments.push({ key: "other", label: "その他の品番", value: other, share: other / total, color: OTHER_COLOR, ink: null, isGroup: true });
    }
    if (hasUnregistered) {
        segments.push({ key: "unregistered", label: "未登録のSKU", value: unregistered, share: unregistered / total, color: UNREGISTERED_COLOR, ink: null, isGroup: true });
    }
    return segments;
}

/** 凡例・一覧の色の印（8～10位は斜線を重ねる）。 */
export function swatchStyle(segment: Pick<DonutSegment, "color" | "ink">): Record<string, string> {
    return segment.ink ? { backgroundColor: segment.color, backgroundImage: `repeating-linear-gradient(45deg, ${segment.ink} 0 1.5px, transparent 1.5px 4px)` } : { backgroundColor: segment.color };
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
