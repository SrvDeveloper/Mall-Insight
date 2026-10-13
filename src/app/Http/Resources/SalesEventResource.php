<?php

namespace App\Http\Resources;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Models\SalesEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * イベント（B-129）。今日から見た状態（これから・開催中・終わった）と、モールのチャネルを添える。
 *
 * @mixin SalesEvent
 */
class SalesEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status(today()->toImmutable());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'days' => $this->days(),
            'malls' => $this->malls->sortBy(fn (Mall $mall): int => array_search($mall, Mall::displayOrder(), true))->map(fn (Mall $mall): array => ['value' => $mall->value, 'label' => $mall->label()])->values()->all(),
            'channels' => array_map(fn (Channel $channel): string => $channel->value, $this->channels()),
            'note' => $this->note,
            'status' => $status->value,
            'status_label' => $status->label(),
            'created_by' => $this->createdBy?->name,
            'updated_by' => $this->updatedBy?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
