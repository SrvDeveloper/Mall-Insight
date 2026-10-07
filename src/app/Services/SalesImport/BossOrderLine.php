<?php

namespace App\Services\SalesImport;

use App\Enums\Mall;
use App\Enums\Warehouse;

/**
 * BOSS受注実績の1行（検証済み）。金額は税込に換算済み。販売日は日本時間の日付（Y-m-d）。
 * 1年分（約2万4千行）を一度に扱うため、日時のオブジェクトは持たない。
 * 注文の商品合計金額と商品ID は取込時の検証（金額の一致・重複）にだけ使い、保存しない。
 */
final readonly class BossOrderLine
{
    public function __construct(
        public int $rowNumber,
        public string $orderId,
        public Mall $mall,
        public string $salesDate,
        public int $itemTotal,
        public string $lineId,
        public string $skuCode,
        public int $quantity,
        public int $lineAmount,
        public Warehouse $warehouse,
    ) {}
}
