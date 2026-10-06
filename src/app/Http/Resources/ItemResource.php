<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
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
            'item_no' => $this->item_no,
            'brand' => $this->brand,
            'category' => $this->category,
            'parent_asin' => $this->parent_asin,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'crosswalker_updated_at' => $this->crosswalker_updated_at?->toIso8601String(),
            'skus' => SkuResource::collection($this->whenLoaded('skus')),
        ];
    }
}
