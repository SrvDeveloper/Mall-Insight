<?php

namespace App\Http\Resources;

use App\Models\ItemSelection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemSelection
 */
class ItemSelectionResource extends JsonResource
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
            'confirmed_at' => $this->confirmed_at->toIso8601String(),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy?->name),
            'ranking_from' => $this->ranking_from->toDateString(),
            'ranking_to' => $this->ranking_to->toDateString(),
            'note' => $this->note,
            'items' => ItemSelectionItemResource::collection($this->whenLoaded('items')),
            'changes' => $this->when($this->relationLoaded('previous'), fn () => $this->changesFromPrevious()),
        ];
    }
}
