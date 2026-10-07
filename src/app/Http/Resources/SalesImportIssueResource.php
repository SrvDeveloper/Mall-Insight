<?php

namespace App\Http\Resources;

use App\Models\SalesImportIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesImportIssue
 */
class SalesImportIssueResource extends JsonResource
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
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'row_number' => $this->row_number,
            'source_order_id' => $this->source_order_id,
            'sku_code' => $this->sku_code,
            'message' => $this->message,
        ];
    }
}
