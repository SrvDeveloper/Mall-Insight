<?php

namespace App\Http\Resources;

use App\Models\SalesEventPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * イベントの期間（B-129、K-114）。今日から見た状態（これから・開催中・終わった）を添える。
 *
 * @mixin SalesEventPeriod
 */
class SalesEventPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status(today()->toImmutable());

        return [
            'id' => $this->id,
            'event_id' => $this->sales_event_id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'days' => $this->days(),
            'note' => $this->note,
            'status' => $status->value,
            'status_label' => $status->label(),
            'updated_by' => $this->updatedBy?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
