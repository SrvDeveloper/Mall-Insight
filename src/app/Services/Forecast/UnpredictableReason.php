<?php

namespace App\Services\Forecast;

/**
 * 需要予測を出せない理由（決定記録 K-035）。0と表示すると「売れない」と誤解されるため、理由を示して予測不能とする。
 */
enum UnpredictableReason: string
{
    /** 3期間とも、販売実績の日数が14日に満たない（欠品日は無い）。 */
    case InsufficientDays = 'insufficient_days';

    /** 欠品していた日が多く、3期間とも使えない。 */
    case Stockout = 'stockout';

    /** 使える期間に1点も売れていない。欠品していた可能性があり、0と予測しない。 */
    case NoSales = 'no_sales';

    public function label(): string
    {
        return match ($this) {
            self::InsufficientDays => '販売実績の日数が足りません（'.DemandForecaster::MIN_SALES_DAYS.'日未満）',
            self::Stockout => '欠品していた日が多く、平均日販を出せません',
            self::NoSales => '直近'.array_sum(array_keys(DemandForecaster::WINDOW_WEIGHTS)).'日に販売実績がありません',
        };
    }
}
