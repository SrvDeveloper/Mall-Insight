<?php

namespace App\Services\CrossWalker;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CrossWalker 外部API（docs/参考/API仕様/CrossWalker/API仕様.md）のクライアント。
 *
 * @phpstan-type CrossWalkerSku array{sku_code: string, child_asin: ?string, status: string, tq_item_no: string, tq_color_no: string, tq_size: string}
 * @phpstan-type CrossWalkerItem array{item_no: string, brand: string, category: string, parent_asin: ?string, status: string, skus: list<CrossWalkerSku>, updated_at: ?string}
 */
class CrossWalkerClient
{
    /** 1ページの取得件数（APIの上限） */
    private const PER_PAGE = 100;

    public const MOCK_DATA_PATH = 'data/crosswalker-items.json';

    /**
     * 無効なものも含め、すべての品番と所属SKUを取得する。
     *
     * @return list<CrossWalkerItem>
     *
     * @throws CrossWalkerException
     */
    public function fetchAllItems(): array
    {
        if (config('services.crosswalker.mock')) {
            return $this->mockItems();
        }

        $items = [];
        $page = 1;
        do {
            $body = $this->get('/items', ['status' => 'all', 'per_page' => self::PER_PAGE, 'page' => $page]);
            if (! is_array($body['data'] ?? null) || ! is_int($body['meta']['last_page'] ?? null)) {
                throw CrossWalkerException::invalidResponse();
            }
            array_push($items, ...$body['data']);
            $lastPage = $body['meta']['last_page'];
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     *
     * @throws CrossWalkerException
     */
    private function get(string $path, array $query): array
    {
        try {
            return $this->request()->get($path, $query)->throw()->json() ?? [];
        } catch (ConnectionException) {
            throw CrossWalkerException::connectionFailed();
        } catch (RequestException $exception) {
            throw CrossWalkerException::fromStatus($exception->response->status());
        }
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.crosswalker.base_url');
        $apiKey = config('services.crosswalker.api_key');
        if (! $baseUrl || ! $apiKey) {
            throw CrossWalkerException::notConfigured();
        }

        // 取得は参照だけの GET のため、通信断・サーバーエラー・回数制限は時間をおいて再試行してよい
        return Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout(config('services.crosswalker.connect_timeout'))
            ->timeout(config('services.crosswalker.timeout'))
            ->retry([500, 2000], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->serverError() || $exception->response->status() === 429)));
    }

    /**
     * @return list<CrossWalkerItem>
     */
    private function mockItems(): array
    {
        return json_decode(file_get_contents(database_path(self::MOCK_DATA_PATH)), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
