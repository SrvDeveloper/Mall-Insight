<?php

namespace App\Services\InventoryTrend;

/**
 * SKUの在庫推移を計算できたか。計算できないときは0にせず、理由を示す（プロダクト概要 原則2）。
 */
enum TrendStatus: string
{
    case Calculated = 'calculated';
    /** 需要予測が出せない */
    case Unpredictable = 'unpredictable';
    /** 最新の在庫基準日に、このSKUの在庫の記録が無い */
    case NoStock = 'no_stock';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => '計算済み',
            self::Unpredictable => '需要予測が出せないため、在庫推移を計算できません',
            self::NoStock => '在庫の記録が無いため、在庫推移を計算できません（ZeroStockViewの対象外の可能性があります）',
        };
    }
}
