<?php

namespace App\Models;

use App\Enums\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 在庫基準日・SKU・在庫の区分ごとの在庫数。ZeroStockView から取得する（バックログ B-003）。
 */
#[Fillable(['stock_date', 'sku_id', 'warehouse', 'quantity'])]
class Inventory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock_date' => 'date',
            'warehouse' => Warehouse::class,
        ];
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
