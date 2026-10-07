<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 販売実績ファイルの取込記録（1ファイルにつき1行）。
 */
#[Fillable(['source', 'file_name', 'status', 'started_at', 'finished_at', 'row_count', 'created_line_count', 'updated_line_count', 'skipped_line_count', 'error_row_count', 'created_sku_count', 'sales_date_from', 'sales_date_to', 'error_message'])]
class SalesImport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'sales_date_from' => 'date',
            'sales_date_to' => 'date',
        ];
    }

    /**
     * @return HasMany<SalesImportIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SalesImportIssue::class)->orderBy('id');
    }
}
