<?php

namespace App\Http\Resources;

use App\Services\SalesTarget\ItemTargetPlan;
use App\Services\SalesTarget\SkuTargetPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 品番1件の販売目標と、月・SKUへの割り振り。数量は小数2桁で返し、整数への丸めは表示するときに行う。金額（税込、概算）は円に丸めて返す（K-062）。
 *
 * @mixin ItemTargetPlan
 */
class ItemTargetPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $round = fn (?array $values): ?array => $values === null ? null : array_map(fn (float $value): float => round($value, 2), $values);

        return [
            'item_no' => $this->itemNo,
            'brand' => $this->item?->brand,
            'category' => $this->item?->category,
            'exists_in_crosswalker' => $this->item !== null,
            'is_target_item' => $this->isTargetItem,
            'ratio_category_id' => $this->category?->id,
            'ratio_category_name' => $this->category?->name,
            'is_ratio_category_set' => $this->isCategorySet,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'target' => $this->target === null ? null : SalesTargetResource::make($this->target)->resolve($request),
            'monthly' => $round($this->itemMonthly),
            'month_overridden' => $this->monthOverridden,
            'sku_total' => $this->skuTotal === null ? null : round($this->skuTotal, 2),
            'unit_price' => $this->registeredUnitPrice,
            'actual_unit_price' => $this->actualUnitPrice === null ? null : round($this->actualUnitPrice),
            'annual_amount' => $this->annualAmount === null ? null : round($this->annualAmount),
            'monthly_amounts' => $this->monthlyAmounts === null ? null : array_map(fn (float $value): float => round($value), $this->monthlyAmounts),
            'has_unpriced_sku' => $this->hasUnpricedSku,
            'skus' => array_map(fn (SkuTargetPlan $sku): array => [
                'sku_id' => $sku->sku->id,
                'sku_code' => $sku->sku->sku_code,
                'sales_quantity' => $sku->salesQuantity,
                'default_annual' => $sku->defaultAnnual === null ? null : round($sku->defaultAnnual, 2),
                'override_annual' => $sku->overrideAnnual,
                'annual' => $sku->annual === null ? null : round($sku->annual, 2),
                'monthly' => $round($sku->monthly),
                'unit_price' => $sku->unitPrice === null ? null : round($sku->unitPrice),
                'price_basis' => $sku->priceBasis?->value,
                'price_basis_label' => $sku->priceBasis?->label(),
                'annual_amount' => $sku->annualAmount === null ? null : round($sku->annualAmount),
                'monthly_amounts' => $sku->monthlyAmounts === null ? null : array_map(fn (float $value): float => round($value), $sku->monthlyAmounts),
            ], $this->skus),
        ];
    }
}
