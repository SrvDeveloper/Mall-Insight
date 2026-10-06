<?php

namespace App\Http\Resources;

use App\Models\ZeroStockViewSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ZeroStockViewSync
 */
class ZeroStockViewSyncResource extends JsonResource
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
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'stock_date_count' => $this->stock_date_count,
            'sku_row_count' => $this->sku_row_count,
            'created_sku_count' => $this->created_sku_count,
            'latest_stock_date' => $this->latest_stock_date?->toDateString(),
            'error_message' => $this->error_message,
        ];
    }
}
