<?php

namespace App\Services\CrossWalker;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\CrossWalkerSync;
use App\Models\Item;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Services\SyncAlreadyRunningException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CrossWalker の品番・SKUを Mall Insight に写す（バックログ B-002、決定記録 K-018・K-019）。
 *
 * CrossWalker を正とする。取得した品番・SKUで保存済みの内容を上書きし、CrossWalker から消えた品番は削除する。
 * 消えた品番に属していたSKUは、販売実績・在庫とのつながりを残すため削除せず、品番に未登録のSKUとして残す。
 *
 * @phpstan-import-type CrossWalkerItem from CrossWalkerClient
 */
class ItemSynchronizer
{
    private const LOCK_KEY = 'crosswalker-item-sync';

    private const LOCK_SECONDS = 300;

    public function __construct(private CrossWalkerClient $client) {}

    /**
     * 取得を1回実行し、その記録を返す。取得に失敗した場合も、失敗の記録を返す（例外にしない）。
     *
     * @throws SyncAlreadyRunningException 別の取得が実行中のとき
     */
    public function sync(SyncTrigger $trigger): CrossWalkerSync
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new SyncAlreadyRunningException;
        }

        try {
            return $this->run($trigger);
        } finally {
            $lock->release();
        }
    }

    private function run(SyncTrigger $trigger): CrossWalkerSync
    {
        $startedAt = now();

        try {
            $items = $this->client->fetchAllItems();
            // 0件で上書きすると全品番が消えるため、異常な応答として扱う
            if ($items === []) {
                throw CrossWalkerException::noItems();
            }
            $counts = DB::transaction(fn (): array => $this->store($items));
        } catch (CrossWalkerException $exception) {
            Log::warning('CrossWalker item sync failed', ['message' => $exception->getMessage()]);

            return CrossWalkerSync::create([
                'status' => SyncStatus::Failed,
                'triggered_by' => $trigger,
                'started_at' => $startedAt,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }

        return CrossWalkerSync::create([
            'status' => SyncStatus::Succeeded,
            'triggered_by' => $trigger,
            'started_at' => $startedAt,
            'finished_at' => now(),
            ...$counts,
        ]);
    }

    /**
     * Amazon の販売実績で見つかった未登録の ASIN は、ASIN をSKUコードにした仮のSKUとして保存している（決定記録 K-040）。
     * その ASIN を子ASINに持つSKUが CrossWalker に現れたら、仮のSKUを CrossWalker のSKUに置き換え、販売実績を引き継ぐ。
     *
     * @param  list<array{sku_code: string, child_asin: ?string}>  $skuRows
     */
    private function linkAsinPlaceholders(array $skuRows): void
    {
        $codeByAsin = [];
        foreach ($skuRows as $row) {
            if ($row['child_asin'] !== null && $row['child_asin'] !== '') {
                $codeByAsin[$row['child_asin']] = $row['sku_code'];
            }
        }
        if ($codeByAsin === []) {
            return;
        }

        $placeholders = Sku::query()->whereNull('item_id')->whereIn('sku_code', array_map('strval', array_keys($codeByAsin)))->whereColumn('sku_code', 'child_asin')->get();
        foreach ($placeholders as $placeholder) {
            $skuCode = $codeByAsin[$placeholder->sku_code];
            $existing = Sku::query()->where('sku_code', $skuCode)->first();
            if ($existing === null) {
                $placeholder->update(['sku_code' => $skuCode]);

                continue;
            }
            SalesLine::query()->where('sku_id', $placeholder->id)->update(['sku_id' => $existing->id]);
            $placeholder->delete();
        }
    }

    /**
     * @param  list<CrossWalkerItem>  $items
     * @return array{item_count: int, sku_count: int, added_item_count: int, removed_item_count: int, detached_sku_count: int}
     */
    private function store(array $items): array
    {
        $fetchedItemNos = array_column($items, 'item_no');
        $existingItemNos = Item::query()->pluck('item_no')->all();

        Item::upsert(
            array_map(fn (array $item): array => [
                'item_no' => $item['item_no'],
                'brand' => $item['brand'],
                'category' => $item['category'],
                'parent_asin' => $item['parent_asin'],
                'status' => $item['status'],
                'crosswalker_updated_at' => $this->toLocalDateTime($item['updated_at']),
            ], $items),
            ['item_no'],
            ['brand', 'category', 'parent_asin', 'status', 'crosswalker_updated_at'],
        );

        $itemIds = Item::query()->whereIn('item_no', $fetchedItemNos)->pluck('id', 'item_no');
        $skuRows = [];
        foreach ($items as $item) {
            foreach (array_values($item['skus']) as $position => $sku) {
                $skuRows[] = [
                    'sku_code' => $sku['sku_code'],
                    'item_id' => $itemIds[$item['item_no']],
                    'child_asin' => $sku['child_asin'],
                    'status' => $sku['status'],
                    'tq_item_no' => $sku['tq_item_no'],
                    'tq_color_no' => $sku['tq_color_no'],
                    'tq_size' => $sku['tq_size'],
                    'position' => $position,
                ];
            }
        }
        $this->linkAsinPlaceholders($skuRows);
        foreach (array_chunk($skuRows, 500) as $chunk) {
            Sku::upsert($chunk, ['sku_code'], ['item_id', 'child_asin', 'status', 'tq_item_no', 'tq_color_no', 'tq_size', 'position']);
        }

        $detachedSkuCount = Sku::query()
            ->whereNotNull('item_id')
            ->whereNotIn('sku_code', array_column($skuRows, 'sku_code'))
            ->update(['item_id' => null, 'child_asin' => null, 'status' => null, 'tq_item_no' => null, 'tq_color_no' => null, 'tq_size' => null, 'position' => null]);

        $removedItemCount = Item::query()->whereNotIn('item_no', $fetchedItemNos)->delete();

        return [
            'item_count' => count($items),
            'sku_count' => count($skuRows),
            'added_item_count' => count(array_diff($fetchedItemNos, $existingItemNos)),
            'removed_item_count' => $removedItemCount,
            'detached_sku_count' => $detachedSkuCount,
        ];
    }

    /**
     * CrossWalker の UTC の日時を、アプリのタイムゾーン（日本時間、K-009）に変換する。
     */
    private function toLocalDateTime(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
