<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 測った結果の1行（品番・方式・何か月先・チャネル・起点ごと、K-111）。WAPE などは集計するときに求める。
 */
#[Fillable(['forecast_backtest_id', 'horizon', 'method', 'item_no', 'channel', 'origin', 'evaluated', 'skipped', 'actual', 'forecast', 'abs_error'])]
class ForecastBacktestResult extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => 'date',
            'evaluated' => 'integer',
            'skipped' => 'integer',
            'actual' => 'float',
            'forecast' => 'float',
            'abs_error' => 'float',
        ];
    }
}
