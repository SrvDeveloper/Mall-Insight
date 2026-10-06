<?php

namespace App\Services\ZeroStockView;

use App\Services\CrossWalker\CrossWalkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ZeroStockView 外部API（docs/参考/API仕様/ZeroStockView/API仕様.md）のクライアント。
 *
 * @phpstan-type DailyInventoryRow array{date: string, productCode: string, brand: string, category: string, sku: string, size: string, amazonOwn: int, amazonFba: int, bossOwn: int, bossRfc: int, freeStock: int, ecStock: int}
 */
class ZeroStockViewClient
{
    /**
     * 1回に指定できる期間の日数。仕様書は365日だが、実際のAPIは31日を超えると 422 を返す（2026年10月6日の接続テストで確認）。
     */
    public const MAX_RANGE_DAYS = 31;

    /**
     * 期間内の日次在庫を取得する。期間が31日を超える場合は区切って取得する。調査のない日は返らない。
     *
     * @return list<DailyInventoryRow>
     *
     * @throws ZeroStockViewException
     */
    public function fetchDailyInventory(Carbon $from, Carbon $to): array
    {
        $rows = [];
        for ($start = $from->copy()->startOfDay(); $start->lte($to); $start = $start->copy()->addDays(self::MAX_RANGE_DAYS)) {
            $end = $start->copy()->addDays(self::MAX_RANGE_DAYS - 1)->min($to);
            array_push($rows, ...$this->fetchRange($start, $end));
        }

        return $rows;
    }

    /**
     * @return list<DailyInventoryRow>
     */
    private function fetchRange(Carbon $from, Carbon $to): array
    {
        if (config('services.zerostockview.mock')) {
            return $this->mockRows($from, $to);
        }

        $baseUrl = config('services.zerostockview.base_url');
        $apiKey = config('services.zerostockview.api_key');
        if (! $baseUrl || ! $apiKey) {
            throw ZeroStockViewException::notConfigured();
        }

        try {
            // 取得は参照だけの GET のため、通信断・サーバーエラー・回数制限は時間をおいて再試行してよい（503 は API 無効のため再試行しない）
            $body = Http::baseUrl($baseUrl)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->connectTimeout(config('services.zerostockview.connect_timeout'))
                ->timeout(config('services.zerostockview.timeout'))
                ->retry([1000, 3000], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->status() === 429 || ($exception->response->serverError() && $exception->response->status() !== 503))))
                ->get('/inventory/daily', ['from' => $from->toDateString(), 'to' => $to->toDateString()])
                ->throw()
                ->json();
        } catch (ConnectionException) {
            throw ZeroStockViewException::connectionFailed();
        } catch (RequestException $exception) {
            throw ZeroStockViewException::fromStatus($exception->response->status());
        }

        if (! is_array($body['data'] ?? null)) {
            throw ZeroStockViewException::invalidResponse();
        }

        return $body['data'];
    }

    /**
     * モック：CrossWalker のサンプルSKUについて、直近60日の平日の在庫を生成する（同じ日・SKUなら毎回同じ値）。
     *
     * @return list<DailyInventoryRow>
     */
    private function mockRows(Carbon $from, Carbon $to): array
    {
        $items = json_decode(file_get_contents(database_path(CrossWalkerClient::MOCK_DATA_PATH)), true, flags: JSON_THROW_ON_ERROR)['data'];
        $historyStart = today()->subDays(60);
        $rows = [];
        for ($date = $from->copy()->max($historyStart); $date->lte($to->copy()->min(today())); $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }
            foreach ($items as $item) {
                foreach ($item['skus'] as $sku) {
                    $seed = crc32($date->toDateString().$sku['sku_code']);
                    $rows[] = [
                        'date' => $date->toDateString(),
                        'productCode' => $item['item_no'],
                        'brand' => $item['brand'],
                        'category' => $item['category'],
                        'sku' => $sku['sku_code'],
                        'size' => $sku['tq_size'],
                        'amazonOwn' => $seed % 12,
                        'amazonFba' => intdiv($seed, 7) % 40,
                        'bossOwn' => intdiv($seed, 11) % 20,
                        'bossRfc' => intdiv($seed, 13) % 70,
                        'freeStock' => intdiv($seed, 17) % 90,
                        'ecStock' => intdiv($seed, 19) % 300,
                    ];
                }
            }
        }

        return $rows;
    }
}
