/**
 * 入荷予定の自動割り振り（K-054）。まだ割り振っていない残りを、SKUの比率（需要予測の基準平均日販）で分ける。
 * 入力済みの割り振りはそのまま残し、残りを足す。整数にするときは、端数の大きいSKUから1個ずつ足して、合計を残りとぴったり合わせる（最大剰余法）。
 *
 * @param remaining まだ割り振っていない数
 * @param weights SKU ID => 比率。0以下・予測できないSKUは含めない
 * @returns SKU ID => 足す数。比率のあるSKUが無いか、残りが無ければ空
 */
export function allocateByWeights(remaining: number, weights: Map<number, number>): Map<number, number> {
    const entries = [...weights].filter(([, weight]) => weight > 0);
    const total = entries.reduce((sum, [, weight]) => sum + weight, 0);
    const result = new Map<number, number>();
    if (remaining <= 0 || total <= 0) {
        return result;
    }

    const shares = entries.map(([skuId, weight], order) => {
        const exact = (remaining * weight) / total;
        return { skuId, order, floor: Math.floor(exact), fraction: exact - Math.floor(exact) };
    });
    let leftover = remaining - shares.reduce((sum, share) => sum + share.floor, 0);
    // 端数の大きい順（同じなら並び順）に1個ずつ足す
    for (const share of [...shares].sort((a, b) => b.fraction - a.fraction || a.order - b.order)) {
        if (leftover <= 0) {
            break;
        }
        share.floor++;
        leftover--;
    }
    for (const share of shares) {
        if (share.floor > 0) {
            result.set(share.skuId, share.floor);
        }
    }
    return result;
}
