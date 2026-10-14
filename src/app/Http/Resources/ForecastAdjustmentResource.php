<?php

namespace App\Http\Resources;

use App\Models\ForecastAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 手動での予測補正（B-109、K-132）。月は Y-m で返す。status は掛けている（active）・始める月がまだ（scheduled）・終えた（ended）・取り消した（cancelled）。
 *
 * @mixin ForecastAdjustment
 */
class ForecastAdjustmentResource extends JsonResource
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
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'starts_on' => $this->starts_on->format('Y-m'),
            'ends_on' => $this->ends_on?->format('Y-m'),
            'rate_percent' => $this->rate_percent,
            'reason' => $this->reason,
            'status' => $this->status(CarbonImmutable::today()),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn (): ?string => $this->cancelledBy?->name),
        ];
    }
}
