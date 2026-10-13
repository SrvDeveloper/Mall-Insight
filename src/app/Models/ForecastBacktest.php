<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 需要予測の当たり具合を測った記録（B-130、K-111）。1回測るごとに1行。結果は品番・方式・何か月先・チャネル・起点ごとに results に持つ。
 * 予測の方式を変えた前後で比べられるよう、最新20件を残す。
 */
#[Fillable(['measured_at', 'measured_by_user_id', 'check_month_offset', 'settings', 'item_count', 'sku_count', 'duration_ms'])]
class ForecastBacktest extends Model
{
    /** 残す件数 */
    public const RETAINED = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'check_month_offset' => 'integer',
            'settings' => 'array',
            'item_count' => 'integer',
            'sku_count' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by_user_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ForecastBacktestResult::class);
    }
}
