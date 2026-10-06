<?php

namespace App\Http\Resources;

use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sku
 */
class SkuResource extends JsonResource
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
            'sku_code' => $this->sku_code,
            'child_asin' => $this->child_asin,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'tq_item_no' => $this->tq_item_no,
            'tq_color_no' => $this->tq_color_no,
            'tq_size' => $this->tq_size,
        ];
    }
}
