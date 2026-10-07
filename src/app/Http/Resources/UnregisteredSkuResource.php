<?php

namespace App\Http\Resources;

use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 品番に属していないSKUと、販売実績・在庫に出てきた状況（UnregisteredSkuController の集計結果）。
 *
 * @mixin Sku
 */
class UnregisteredSkuResource extends JsonResource
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
            'recent_sales_quantity' => (int) $this->recent_sales_quantity,
            'first_sold_on' => $this->toDate($this->first_sold_on),
            'last_sold_on' => $this->toDate($this->last_sold_on),
            'first_stocked_on' => $this->toDate($this->first_stocked_on),
            'last_stocked_on' => $this->toDate($this->last_stocked_on),
        ];
    }

    private function toDate(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
