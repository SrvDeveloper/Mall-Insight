<?php

namespace App\Models;

use App\Enums\SnapshotTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 保存した需要予測の版（B-108、決定記録 K-136）。画面は今までどおり開くたびに計算し（K-035）、版は「その時点でどう予測していたか」の記録と、
 * 後で実績と比べる答え合わせに使う。毎月1日に自動で保存し、画面からも保存できる。値は SKU・チャネル・月ごとに values に持つ。
 */
#[Fillable(['taken_at', 'forecast_from', 'trigger', 'created_by_user_id', 'check_month_offset', 'channels', 'target_items', 'adjustments', 'seasonal_item_nos', 'item_count', 'sku_count'])]
class ForecastSnapshot extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_at' => 'immutable_datetime',
            'forecast_from' => 'immutable_date',
            'trigger' => SnapshotTrigger::class,
            'check_month_offset' => 'integer',
            'channels' => 'array',
            'target_items' => 'array',
            'adjustments' => 'array',
            'seasonal_item_nos' => 'array',
            'item_count' => 'integer',
            'sku_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<ForecastSnapshotValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ForecastSnapshotValue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
