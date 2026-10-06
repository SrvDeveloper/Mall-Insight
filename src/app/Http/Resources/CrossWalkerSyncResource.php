<?php

namespace App\Http\Resources;

use App\Models\CrossWalkerSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CrossWalkerSync
 */
class CrossWalkerSyncResource extends JsonResource
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'triggered_by' => $this->triggered_by->value,
            'triggered_by_label' => $this->triggered_by->label(),
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at->toIso8601String(),
            'item_count' => $this->item_count,
            'sku_count' => $this->sku_count,
            'added_item_count' => $this->added_item_count,
            'removed_item_count' => $this->removed_item_count,
            'detached_sku_count' => $this->detached_sku_count,
            'error_message' => $this->error_message,
        ];
    }
}
