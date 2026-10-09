<?php

namespace App\Services\ZeroStockView;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Enums\Warehouse;
use App\Models\Inventory;
use App\Models\Sku;
use App\Models\ZeroStockViewSync;
use App\Services\ImportHistoryRetention;
use App\Services\SyncAlreadyRunningException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ZeroStockView の日次在庫を取得して保存する（バックログ B-003、決定記録 K-007・K-021）。
 *
 * - 初回（在庫が1件も無いとき）は、履歴をさかのぼれるだけ取り込む。
 * - 2回目以降は、再照合による値の変化を拾うため直近7日分を取り直す。前回の最新日より前から途切れている場合は、そこから取り直す。
 * - 取得できた在庫基準日は、その日の在庫をまるごと置き換える。取得できなかった日（調査のない日）は変更しない。
 *
 * @phpstan-import-type DailyInventoryRow from ZeroStockViewClient
 */
class InventorySynchronizer
{
    public const REFETCH_DAYS = 7;

    /** 初回の取り込みで、データの無い期間（31日）がこの回数続いたら履歴の始まりとみなす */
    private const EMPTY_WINDOWS_TO_STOP = 3;

    /** 初回の取り込みでさかのぼる上限（31日×24 ≒ 2年） */
    private const MAX_BACKFILL_WINDOWS = 24;

    private const LOCK_KEY = 'zerostockview-inventory-sync';

    private const LOCK_SECONDS = 600;

    public function __construct(private ZeroStockViewClient $client, private ImportHistoryRetention $historyRetention) {}

    /**
     * 取得を1回実行し、その記録を返す。取得に失敗した場合も、失敗の記録を返す（例外にしない）。
     *
     * @throws SyncAlreadyRunningException 別の取得が実行中のとき
     */
    public function sync(SyncTrigger $trigger): ZeroStockViewSync
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new SyncAlreadyRunningException('ZeroStockViewからの取得を実行中です。終わるまでお待ちください。');
        }

        try {
            $sync = $this->run($trigger);
            // 失敗が続いても前回取得した日時を表示できるよう、最後に成功した記録は残す
            $this->historyRetention->prune(ZeroStockViewSync::query(), ZeroStockViewSync::query()->succeeded()->latestFirst()->value('id'));

            return $sync;
        } finally {
            $lock->release();
        }
    }

    private function run(SyncTrigger $trigger): ZeroStockViewSync
    {
        $startedAt = now();
        $latestStored = Inventory::query()->max('stock_date');

        try {
            [$from, $to, $rows] = $latestStored === null ? $this->fetchHistory() : $this->fetchRecent(Carbon::parse($latestStored));
            $counts = DB::transaction(fn (): array => $this->store($rows));
        } catch (ZeroStockViewException $exception) {
            Log::warning('ZeroStockView inventory sync failed', ['message' => $exception->getMessage()]);

            return ZeroStockViewSync::create([
                'status' => SyncStatus::Failed,
                'triggered_by' => $trigger,
                'started_at' => $startedAt,
                'finished_at' => now(),
                'latest_stock_date' => $latestStored,
                'error_message' => $exception->getMessage(),
            ]);
        }

        return ZeroStockViewSync::create([
            'status' => SyncStatus::Succeeded,
            'triggered_by' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'from_date' => $from,
            'to_date' => $to,
            'latest_stock_date' => Inventory::query()->max('stock_date'),
            ...$counts,
        ]);
    }

    /**
     * @return array{Carbon, Carbon, list<DailyInventoryRow>}
     */
    private function fetchRecent(Carbon $latestStored): array
    {
        $to = today();
        $from = today()->subDays(self::REFETCH_DAYS - 1)->min($latestStored);

        return [$from, $to, $this->client->fetchDailyInventory($from, $to)];
    }

    /**
     * @return array{Carbon, Carbon, list<DailyInventoryRow>}
     */
    private function fetchHistory(): array
    {
        $to = today();
        $windowEnd = $to->copy();
        $from = $to->copy();
        $rows = [];
        $emptyWindows = 0;
        for ($window = 0; $window < self::MAX_BACKFILL_WINDOWS && $emptyWindows < self::EMPTY_WINDOWS_TO_STOP; $window++) {
            $windowStart = $windowEnd->copy()->subDays(ZeroStockViewClient::MAX_RANGE_DAYS - 1);
            $windowRows = $this->client->fetchDailyInventory($windowStart, $windowEnd);
            $emptyWindows = $windowRows === [] ? $emptyWindows + 1 : 0;
            array_push($rows, ...$windowRows);
            $from = $windowStart;
            $windowEnd = $windowStart->copy()->subDay();
        }

        return [$from, $to, $rows];
    }

    /**
     * @param  list<DailyInventoryRow>  $rows
     * @return array{stock_date_count: int, sku_row_count: int, created_sku_count: int}
     */
    private function store(array $rows): array
    {
        $skuCodes = array_values(array_unique(array_column($rows, 'sku')));
        $skuIds = Sku::query()->whereIn('sku_code', $skuCodes)->pluck('id', 'sku_code');

        // CrossWalker に無いSKUも、在庫を失わないよう品番に未登録のSKUとして保存する（B-003、K-019）
        $newSkuCodes = array_values(array_diff($skuCodes, $skuIds->keys()->all()));
        if ($newSkuCodes !== []) {
            Sku::insert(array_map(fn (string $code): array => ['sku_code' => $code, 'created_at' => now(), 'updated_at' => now()], $newSkuCodes));
            $skuIds = Sku::query()->whereIn('sku_code', $skuCodes)->pluck('id', 'sku_code');
        }

        $stockDates = array_values(array_unique(array_column($rows, 'date')));
        Inventory::query()->whereIn('stock_date', $stockDates)->delete();

        $now = now();
        $inventoryRows = [];
        foreach ($rows as $row) {
            foreach (Warehouse::cases() as $warehouse) {
                $inventoryRows[] = [
                    'stock_date' => $row['date'],
                    'sku_id' => $skuIds[$row['sku']],
                    'warehouse' => $warehouse->value,
                    'quantity' => $row[$warehouse->zeroStockViewField()],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($inventoryRows, 1000) as $chunk) {
            Inventory::insert($chunk);
        }

        return [
            'stock_date_count' => count($stockDates),
            'sku_row_count' => count($rows),
            'created_sku_count' => count($newSkuCodes),
        ];
    }
}
