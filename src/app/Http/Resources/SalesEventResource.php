<?php

namespace App\Http\Resources;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Models\SalesEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * イベント（B-129、K-114）。名前・モール・メモと、モールのチャネル。期間は呼び出し側で添える。
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'malls' => $this->malls->sortBy(fn (Mall $mall): int => array_search($mall, Mall::displayOrder(), true))->map(fn (Mall $mall): array => ['value' => $mall->value, 'label' => $mall->label()])->values()->all(),
            'channels' => array_map(fn (Channel $channel): string => $channel->value, $this->channels()),
            'note' => $this->note,
            'created_by' => $this->createdBy?->name,
            'updated_by' => $this->updatedBy?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
