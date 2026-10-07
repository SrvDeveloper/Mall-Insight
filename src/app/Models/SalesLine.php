<?php

namespace App\Models;

use App\Enums\Mall;
use App\Enums\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 販売実績（注文明細1行）。金額は税込の円。作成・更新日時は持たない（決定記録 K-024）。
 */
#[Fillable(['source', 'source_order_id', 'sales_date', 'mall', 'sku_id', 'warehouse', 'quantity', 'amount'])]
class SalesLine extends Model
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
            'sales_date' => 'date',
            'mall' => Mall::class,
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
