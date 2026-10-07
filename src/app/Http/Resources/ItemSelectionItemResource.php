<?php

namespace App\Http\Resources;

use App\Models\ItemSelectionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemSelectionItem
 */
class ItemSelectionItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_no' => $this->item_no,
            'sales_rank' => $this->sales_rank,
            'sales_amount' => $this->sales_amount,
            'is_candidate' => $this->is_candidate,
            'exists_in_crosswalker' => $this->item !== null,
        ];
    }
}
