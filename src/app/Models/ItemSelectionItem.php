<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 選定に含めた品番と、確定時の売上順位・売上金額（決定記録 K-032）。
 */
#[Fillable(['item_no', 'sales_rank', 'sales_amount', 'is_candidate'])]
class ItemSelectionItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_candidate' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ItemSelection, $this>
     */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(ItemSelection::class, 'item_selection_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_no', 'item_no');
    }
}
