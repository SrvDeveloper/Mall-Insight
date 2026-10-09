<?php

namespace App\Http\Resources;

use App\Models\MonthlySalesRatio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 区分1つの月別販売比率（4月～翌3月、1万分率）。
 *
 * @mixin MonthlySalesRatio
 */
class MonthlySalesRatioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ratio_category_id' => $this->ratio_category_id,
            'ratios' => $this->ratios,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'changed_by' => $this->changedBy?->name,
            'changed_at' => $this->created_at->toIso8601String(),
        ];
    }
}
