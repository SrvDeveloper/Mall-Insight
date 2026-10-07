<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 入荷予定のSKUへの割り振り。
 */
#[Fillable(['sku_id', 'quantity'])]
class InboundPlanAllocation extends Model
{
    /**
     * @return BelongsTo<InboundPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InboundPlan::class, 'inbound_plan_id');
    }

    /**
     * @return BelongsTo<Sku, $this>
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
