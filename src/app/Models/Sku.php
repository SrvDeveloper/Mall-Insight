<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SKU。品番に未登録のSKU（販売実績・在庫にだけ現れるSKU）も保存する。
 */
#[Fillable(['item_id', 'sku_code', 'child_asin', 'status', 'tq_item_no', 'tq_color_no', 'tq_size', 'position'])]
class Sku extends Model
{
    /** @use HasFactory<SkuFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<Inventory, $this>
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
