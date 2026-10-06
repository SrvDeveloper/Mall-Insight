<?php

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 在庫基準日ごとの取得状況（InventoryDateController の集計結果）。
 *
 * @mixin Inventory
 */
class InventoryDateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stock_date' => $this->stock_date->toDateString(),
            'sku_count' => (int) $this->sku_count,
            'fetched_at' => Carbon::parse($this->fetched_at)->toIso8601String(),
        ];
    }
}
