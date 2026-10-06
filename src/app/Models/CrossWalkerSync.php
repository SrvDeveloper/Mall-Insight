<?php

namespace App\Models;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CrossWalker からの品番・SKU取得の実行記録（1回の取得につき1行）。
 */
#[Fillable(['status', 'triggered_by', 'started_at', 'finished_at', 'item_count', 'sku_count', 'added_item_count', 'removed_item_count', 'detached_sku_count', 'error_message'])]
class CrossWalkerSync extends Model
{
    protected $table = 'crosswalker_syncs';

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
