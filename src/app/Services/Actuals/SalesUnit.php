<?php

namespace App\Services\Actuals;

/**
 * 品番別売上を何ごとに集めるか（バックログ B-124・B-127）。月ごとは直近12か月と今月、日ごとは直近30日。
 */
enum SalesUnit: string
{
    case Month = 'month';
    case Day = 'day';
}
