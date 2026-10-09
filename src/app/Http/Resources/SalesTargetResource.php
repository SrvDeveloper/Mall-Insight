<?php

namespace App\Http\Resources;

use App\Models\SalesTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 年間販売目標の1つの版（変更1回分）。
 *
 * @mixin SalesTarget
 */
class SalesTargetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiscal_year' => $this->fiscal_year,
            'item_no' => $this->item_no,
            'annual_quantity' => $this->annual_quantity,
            'monthly_quantities' => $this->monthlyOverrides(),
            'sku_quantities' => (object) $this->skuOverrides(),
            'note' => $this->note,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'changed_by' => $this->changedBy?->name,
            'changed_at' => $this->created_at->toIso8601String(),
        ];
    }
}
