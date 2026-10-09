<?php

namespace App\Services\InventoryTrend;

use App\Enums\Channel;
use App\Enums\Warehouse;

/**
 * 在庫推移をどの在庫で見るか（バックログ B-121）。全体は6区分の合計（K-028）、Amazon・BOSSはそのモールの在庫の区分の合計と、
 * そのモールの需要で計算する。フリー在庫・ECストックはモールの推移に含めない（K-070）。
 */
enum TrendScope: string
{
    case Total = 'total';
    case Amazon = 'amazon';
    case Boss = 'boss';

    public function label(): string
    {
        return match ($this) {
            self::Total => '全体',
            self::Amazon => 'Amazon',
            self::Boss => 'BOSS',
        };
    }

    /**
     * モールの推移ならそのチャネル。全体は null。
     */
    public function channel(): ?Channel
    {
        return match ($this) {
            self::Total => null,
            self::Amazon => Channel::Amazon,
            self::Boss => Channel::Boss,
        };
    }

    /**
     * 月初在庫に数える在庫の区分。
     *
     * @return list<Warehouse>
     */
    public function warehouses(): array
    {
        return $this->channel()?->warehouses() ?? Warehouse::cases();
    }

    /**
     * 在庫の区分ごとの在庫数のうち、この推移の月初在庫に数える区分の合計。
     *
     * @param  array<string, int>  $stockByWarehouse  在庫の区分 => 在庫数
     */
    public function stockOf(array $stockByWarehouse): int
    {
        return array_sum(array_map(fn (Warehouse $warehouse): int => $stockByWarehouse[$warehouse->value] ?? 0, $this->warehouses()));
    }
}
