<?php

namespace App\Enums;

/**
 * 販売チャネル。チャネルごとに在庫と補充が別のため、欠品の判定と需要予測もチャネルごとに行う（決定記録 K-038）。
 * BOSS は楽天市場・Yahoo!ショッピング・au PAY マーケットの注文を BOSS の倉庫から出荷する。
 */
enum Channel: string
{
    case Boss = 'boss';
    case Amazon = 'amazon';

    public function label(): string
    {
        return match ($this) {
            self::Boss => 'BOSS',
            self::Amazon => 'Amazon',
        };
    }

    /**
     * このチャネルで売るモール。BOSS は楽天市場・Yahoo!ショッピング・au PAY マーケット、Amazon は Amazon。
     * 需要予測はモールごとに平均日販を求めて足す（B-134、K-126）。
     *
     * @return list<Mall>
     */
    public function malls(): array
    {
        return array_values(array_filter(Mall::cases(), fn (Mall $mall): bool => $mall->channel() === $this));
    }

    /**
     * このチャネルの販売に使う在庫の区分。販売実績の出荷倉庫も、この区分のどれかになる。
     *
     * @return list<Warehouse>
     */
    public function warehouses(): array
    {
        return array_values(array_filter(Warehouse::cases(), fn (Warehouse $warehouse): bool => $warehouse->channel() === $this));
    }
}
