<?php

namespace App\Services\SalesImport;

use App\Enums\Warehouse;

/**
 * Amazon全注文レポートの、出荷済み（Shipped）の1行（検証済み）。金額は税込（item-price）。
 * 販売日は日本時間の日付（Y-m-d）。注文明細ID と購入日時は取込時の検証（重複・注文内の日時の一致）にだけ使い、保存しない。
 */
final readonly class AmazonOrderLine
{
    public function __construct(
        public int $rowNumber,
        public string $orderId,
        public string $lineId,
        public string $purchasedAt,
        public string $salesDate,
        public string $asin,
        public int $quantity,
        public int $lineAmount,
        public Warehouse $warehouse,
    ) {}
}
