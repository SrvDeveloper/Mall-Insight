<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ZeroStockView からの日次在庫取得の実行記録（1回の取得につき1行）。
 */
#[Fillable(['status', 'triggered_by', 'started_at', 'finished_at', 'from_date', 'to_date', 'stock_date_count', 'sku_row_count', 'created_sku_count', 'latest_stock_date', 'error_message'])]
class ZeroStockViewSync extends Model
{
    protected $table = 'zerostockview_syncs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'triggered_by' => SyncTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'from_date' => 'date',
            'to_date' => 'date',
            'latest_stock_date' => 'date',
        ];
    }

    #[Scope]
    protected function succeeded(Builder $query): Builder
    {
        return $query->where('status', SyncStatus::Succeeded);
    }

    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('finished_at')->orderByDesc('id');
    }
}
