<?php

namespace App\Services\Forecast;

/**
 * 予測精度の測定（B-130）で比べる予測の方式（K-111）。今の方式のほかに、補正を入れる前の方式と、当たり具合の目安にする単純な方式を測る。
 */
enum BacktestMethod: string
{
    /** 今の方式。基準平均日販からイベントの日を除き、これからのイベントの日に倍率を掛ける（B-129、K-112） */
    case EventAdjusted = 'event_adjusted';

    /** 補正を入れる前の方式。3つの期間の平均日販を重みでまとめた基準平均日販（B-006、K-036） */
    case BaseAverage = 'base_average';

    /** 直近30日の平均日販（欠品日を除かない） */
    case RecentAverage = 'recent_30';

    /** 前年の同じ月の販売実績 */
    case LastYear = 'last_year';

    public function label(): string
    {
        return match ($this) {
            self::EventAdjusted => '今の方式（イベントの補正あり）',
            self::BaseAverage => '基準平均日販（補正なし）',
            self::RecentAverage => '直近30日の平均',
            self::LastYear => '前年同月の実績',
        };
    }
}
