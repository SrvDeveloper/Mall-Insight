<?php

namespace App\Services\SalesTarget;

/**
 * 品番の販売目標を月・SKUへ割り振れたか。割り振れないときは0にせず、理由を示す（原則2）。
 */
enum TargetPlanStatus: string
{
    case Calculated = 'calculated';
    case NoTarget = 'no_target';
    case NoCategory = 'no_category';
    case NoRatio = 'no_ratio';
    case NoComposition = 'no_composition';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => '割り振り済み',
            self::NoTarget => '年間販売目標が登録されていません',
            self::NoCategory => '月別販売比率の区分が決まっていません。区分を選んでください',
            self::NoRatio => 'この区分の月別販売比率が登録されていません',
            self::NoComposition => '直近12か月の販売実績が無く、SKUへの構成比を出せません。SKUごとの数を入力してください',
        };
    }
}
