<?php

namespace App\Http\Resources;

use App\Models\InboundPlan;
use App\Models\InboundPlanAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InboundPlan
 */
class InboundPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $allocated = $this->allocatedQuantity();

        return [
            'id' => $this->id,
            'item_no' => $this->item_no,
            'brand' => $this->item?->brand,
            'category' => $this->item?->category,
            'exists_in_crosswalker' => $this->item !== null,
            'arrival_month' => $this->arrival_month->format('Y-m'),
            'quantity' => $this->quantity,
            'allocated_quantity' => $allocated,
            'unallocated_quantity' => $this->quantity - $allocated,
            'note' => $this->note,
            'received_at' => $this->received_at?->toIso8601String(),
            // 入荷予定月を過ぎても入荷済みになっていない（入荷済みにするか、月を直す必要がある）
            'is_overdue' => $this->received_at === null && $this->arrival_month->lt(today()->startOfMonth()),
            'allocations' => $this->allocations->map(fn (InboundPlanAllocation $allocation): array => [
                'sku_id' => $allocation->sku_id,
                'sku_code' => $allocation->sku->sku_code,
                'quantity' => $allocation->quantity,
            ])->all(),
        ];
    }
}
