<?php

namespace App\Models;

use App\Enums\ImportIssueLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 販売実績の取込で見つかったエラー・警告。
 */
#[Fillable(['sales_import_id', 'level', 'row_number', 'source_order_id', 'sku_code', 'message'])]
class SalesImportIssue extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ImportIssueLevel::class,
        ];
    }
}
