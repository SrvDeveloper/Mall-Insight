<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 保存した需要予測の版（B-108）の、SKU・チャネル・月ごとの値。予測（補正の後）・補正の前・イベントで増やした数・補正の倍率・基準平均日販を持つ。
 * 予測できなかったチャネルは数量が null で、理由を持つ。
 */
#[Fillable(['forecast_snapshot_id', 'item_no', 'sku_id', 'sku_code', 'channel', 'month', 'quantity', 'unadjusted_quantity', 'event_quantity', 'adjustment_rate', 'base_average', 'unpredictable_reason'])]
class ForecastSnapshotValue extends Model
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
            'month' => 'immutable_date',
            'quantity' => 'float',
            'unadjusted_quantity' => 'float',
            'event_quantity' => 'float',
            'adjustment_rate' => 'float',
            'base_average' => 'float',
        ];
    }

    /**
     * @return BelongsTo<ForecastSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ForecastSnapshot::class, 'forecast_snapshot_id');
    }
}
