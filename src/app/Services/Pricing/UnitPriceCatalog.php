<?php

namespace App\Services\Pricing;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Item;
use App\Models\ItemUnitPrice;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Services\SalesTarget\PriceBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 品番の単価（決定記録 K-063）。登録した単価（全体・Amazon・BOSS）と、直近12か月の販売実績の平均単価をまとめて持つ。
 * 販売実績のモールは、出荷した在庫の区分で分ける（K-038）。
 */
final class UnitPriceCatalog
{
    /** 全モールの合計を表すキー。 */
    public const ALL = 'all';

    /**
     * @param  array<int, array<string, SalesTotal>>  $skuTotals  SKU ID => [all|amazon|boss => 合計]
     * @param  array<string, array<string, SalesTotal>>  $itemTotals  品番 => [all|amazon|boss => 合計]
     * @param  Collection<string, ItemUnitPrice>  $registered  品番 => 登録した単価
     */
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        private array $skuTotals,
        private array $itemTotals,
        private Collection $registered,
    ) {}

    /**
     * 品番（とそのSKU）の単価を、直近12か月（今日まで）の販売実績から作る。
     *
     * @param  Collection<int, Item>  $items  skus を読み込んだ品番
     */
    public static function build(Collection $items, CarbonImmutable $today): self
    {
        $to = $today->startOfDay();
        $from = $to->subYear()->addDay();
        $skuItem = [];
        foreach ($items as $item) {
            foreach ($item->skus as $sku) {
                $skuItem[$sku->id] = $item->item_no;
            }
        }

        $skuTotals = [];
        $itemTotals = [];
        foreach (array_chunk(array_keys($skuItem), 500) as $chunk) {
            $rows = SalesLine::query()
                ->selectRaw('sku_id, warehouse, sum(quantity) as quantity, sum(amount) as amount')
                ->whereIn('sku_id', $chunk)
                ->where('sales_date', '>=', $from->toDateString())
                ->where('sales_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'warehouse')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $total = new SalesTotal((float) $row->quantity, (float) $row->amount);
                $keys = [self::ALL];
                $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
                if ($channel !== null) {
                    $keys[] = $channel->value;
                }
                $itemNo = $skuItem[(int) $row->sku_id];
                foreach ($keys as $key) {
                    $skuTotals[(int) $row->sku_id][$key] = ($skuTotals[(int) $row->sku_id][$key] ?? new SalesTotal)->add($total);
                    $itemTotals[$itemNo][$key] = ($itemTotals[$itemNo][$key] ?? new SalesTotal)->add($total);
                }
            }
        }

        $registered = ItemUnitPrice::query()->with('changedBy')->whereIn('item_no', $items->pluck('item_no'))->get()->keyBy('item_no');

        return new self($from, $to, $skuTotals, $itemTotals, $registered);
    }

    public function registered(string $itemNo): ?ItemUnitPrice
    {
        return $this->registered->get($itemNo);
    }

    /**
     * @param  string  $key  all（全モール）か、Channel の値
     */
    public function skuTotal(int $skuId, string $key = self::ALL): SalesTotal
    {
        return $this->skuTotals[$skuId][$key] ?? new SalesTotal;
    }

    /**
     * @param  string  $key  all（全モール）か、Channel の値
     */
    public function itemTotal(string $itemNo, string $key = self::ALL): SalesTotal
    {
        return $this->itemTotals[$itemNo][$key] ?? new SalesTotal;
    }

    /**
     * モールを分けない金額に使うSKUの単価。品番の全体の単価、無ければSKUの平均単価、そのSKUが売れていなければ品番の平均単価。
     *
     * @return array{0: float|null, 1: PriceBasis|null}
     */
    public function overallPrice(Sku $sku, string $itemNo): array
    {
        $registered = $this->registered($itemNo)?->unit_price;

        return match (true) {
            $registered !== null => [(float) $registered, PriceBasis::Registered],
            $this->skuTotal($sku->id)->averagePrice() !== null => [$this->skuTotal($sku->id)->averagePrice(), PriceBasis::SkuActual],
            $this->itemTotal($itemNo)->averagePrice() !== null => [$this->itemTotal($itemNo)->averagePrice(), PriceBasis::ItemActual],
            default => [null, null],
        };
    }

    /**
     * モール別の金額に使う品番の単価。そのモールの単価、無ければ全体の単価、無ければそのモールの平均単価、無ければ全モールの平均単価。
     */
    public function channelPrice(string $itemNo, Channel $channel): ?float
    {
        $registered = $this->registered($itemNo);
        $price = $registered?->priceFor($channel) ?? $registered?->unit_price;

        return $price !== null ? (float) $price : ($this->itemTotal($itemNo, $channel->value)->averagePrice() ?? $this->itemTotal($itemNo)->averagePrice());
    }

    /**
     * モールを分けない数（需要予測の合計）の金額に使う単価。モールの単価を、モールごとの需要の比率で加重平均する。
     * 合計の金額がAmazonの金額とBOSSの金額の合計になるようにするため（K-073）。比率のあるモールの単価が1つでも出せなければ null。
     *
     * @param  array<string, float>  $weights  Channel の値 => 比率（基準平均日販）
     */
    public function blendedPrice(string $itemNo, array $weights): ?float
    {
        $weights = array_filter($weights, fn (float $weight): bool => $weight > 0);
        if ($weights === []) {
            return null;
        }
        $total = 0.0;
        foreach ($weights as $channel => $weight) {
            $price = $this->channelPrice($itemNo, Channel::from($channel));
            if ($price === null) {
                return null;
            }
            $total += $price * $weight;
        }

        return $total / array_sum($weights);
    }
}
