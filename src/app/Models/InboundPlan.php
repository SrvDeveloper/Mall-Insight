<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 入荷予定（バックログ B-007、決定記録 K-043）。品番・月単位で登録し、SKUへの割り振りは後から入れる。
 * 割り振っていない残りは、在庫推移で需要予測の比率により仮に割り振る。入荷済みにしたものは在庫推移に入れない。
 */
#[Fillable(['item_no', 'arrival_month', 'quantity', 'note', 'received_at'])]
class InboundPlan extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrival_month' => 'date',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_no', 'item_no');
    }

    /**
     * @return HasMany<InboundPlanAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InboundPlanAllocation::class)->orderBy('id');
    }

    /**
     * SKUに割り振った数の合計。allocations を読み込んでから呼ぶ。
     */
    public function allocatedQuantity(): int
    {
        return (int) $this->allocations->sum('quantity');
    }
}
