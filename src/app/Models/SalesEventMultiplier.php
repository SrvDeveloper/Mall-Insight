<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 担当者が入れた、イベントの期間中の品番の売れ方の倍率（B-129、K-112・K-114）。期間ごとに持ち、入れていない品番は、システムが求めた候補を使う。
 */
#[Fillable(['sales_event_period_id', 'item_no', 'channel', 'multiplier', 'updated_by_user_id'])]
class SalesEventMultiplier extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'multiplier' => 'float',
        ];
    }

    /**
     * @return BelongsTo<SalesEventPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(SalesEventPeriod::class, 'sales_event_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
