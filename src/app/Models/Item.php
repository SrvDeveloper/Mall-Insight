<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 品番。CrossWalker から取得し、品番コードを商品名として扱う。
 */
#[Fillable(['item_no', 'brand', 'category', 'parent_asin', 'status', 'crosswalker_updated_at'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
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
            'crosswalker_updated_at' => 'datetime',
        ];
    }

    /**
     * 所属SKU（CrossWalker の表示順）。
     *
     * @return HasMany<Sku, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class)->orderBy('position')->orderBy('id');
    }
}
