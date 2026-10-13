<?php

namespace App\Services\Forecast;

/**
 * 予測精度の測定（B-130）で、起点から何か月先の予測を測るか（K-111）。
 */
enum BacktestHorizon: string
{
    /** 起点の月（起点の1日に出した、その月の予測） */
    case FirstMonth = 'month_1';

    /** 起点の月から3か月目までの合計 */
    case ThreeMonths = 'months_3';

    /** 欠品を判定する月（在庫推移の設定、初期値は6か月後） */
    case CheckMonth = 'check_month';

    public function label(int $checkMonthOffset): string
    {
        return match ($this) {
            self::FirstMonth => '1か月目',
            self::ThreeMonths => '1～3か月目の合計',
            self::CheckMonth => "{$checkMonthOffset}か月後（判定する月）",
        };
    }

    /**
     * 答え合わせする月の位置（起点の月が0）の最初と最後。
     *
     * @return array{0: int, 1: int}
     */
    public function monthOffsets(int $checkMonthOffset): array
    {
        return match ($this) {
            self::FirstMonth => [0, 0],
            self::ThreeMonths => [0, 2],
            self::CheckMonth => [$checkMonthOffset, $checkMonthOffset],
        };
    }
}
