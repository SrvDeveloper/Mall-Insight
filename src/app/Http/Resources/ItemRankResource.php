<?php

namespace App\Http\Resources;

use App\Services\ItemSelection\ItemRank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 品番の売上順位（ItemRanker の結果）。
 *
 * @mixin ItemRank
 */
class ItemRankResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item->id,
            'item_no' => $this->item->item_no,
            'brand' => $this->item->brand,
            'category' => $this->item->category,
            'status' => $this->item->status->value,
            'status_label' => $this->item->status->label(),
            'sku_count' => $this->item->skus_count,
            'sales_amount' => $this->salesAmount,
            'sales_quantity' => $this->salesQuantity,
            'sales_rank' => $this->rank,
            'is_candidate' => $this->isCandidate(),
        ];
    }
}
