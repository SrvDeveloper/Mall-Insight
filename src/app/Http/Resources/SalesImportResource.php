<?php

namespace App\Http\Resources;

use App\Models\SalesImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesImport
 */
class SalesImportResource extends JsonResource
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
            'source' => $this->source,
            'file_name' => $this->file_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at->toIso8601String(),
            'row_count' => $this->row_count,
            'created_line_count' => $this->created_line_count,
            'updated_line_count' => $this->updated_line_count,
            'skipped_line_count' => $this->skipped_line_count,
            'error_row_count' => $this->error_row_count,
            'created_sku_count' => $this->created_sku_count,
            'warning_count' => $this->whenCounted('warnings'),
            'sales_date_from' => $this->sales_date_from?->toDateString(),
            'sales_date_to' => $this->sales_date_to?->toDateString(),
            'error_message' => $this->error_message,
            'issues' => SalesImportIssueResource::collection($this->whenLoaded('issues')),
        ];
    }
}
