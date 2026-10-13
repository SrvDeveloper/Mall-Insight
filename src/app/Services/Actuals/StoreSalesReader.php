<?php

namespace App\Services\Actuals;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Models\SalesLine;
use Carbon\CarbonImmutable;

/**
 * 店舗売上（バックログ B-126）。楽天市場・Yahoo!ショッピング・au PAY マーケット・Amazon の店舗ごとに、月ごとか日ごとの販売数と金額（税込、
 * 取り込んだ金額の合計）を集める。期間と比べ方は品番別売上（K-080・K-082）とそろえる。取り込んだ実績は変えない（原則3）。
 *
 * - 商品は全商品（CrossWalkerに未登録のSKUを含む）か、対象品番のSKUだけ（K-088）。
 * - 店舗の販売実績を取り込んでいるかは、その店舗のチャネルの販売実績の期間で決める（BOSSの3店舗は BOSS 受注実績、Amazon は全注文レポート）。
 *   取り込んでいない期間は0とせず null とする（原則2）。
 */
class StoreSalesReader
{
    public function __construct(private ActualsReader $actuals) {}

    /**
     * @param  list<int>|null  $skuIds  対象品番のSKUだけにするときのSKU。全商品なら null
     * @return array{periods: list<array{start: CarbonImmutable, coverage: Coverage}>, stores: array<string, array{coverage: list<Coverage>, quantities: list<float|null>, amounts: list<float|null>, previous: array{quantities: list<float|null>, amounts: list<float|null>}, last_year: array{quantities: list<float|null>, amounts: list<float|null>}}>} stores は Mall の値 => 店舗の値
     */
    public function sales(CarbonImmutable $today, SalesUnit $unit, CarbonImmutable $from, CarbonImmutable $to, ?array $skuIds): array
    {
        $today = $today->startOfDay();
        $ranges = $this->actuals->salesRanges();
        $channels = array_values(array_filter(Channel::cases(), fn (Channel $channel): bool => isset($ranges[$channel->value])));

        $periods = [];
        foreach ($this->actuals->periods($unit, $today, $from, $to) as [$start, $end, $isIncomplete]) {
            $periods[] = ['start' => $start, 'end' => $end, 'isIncomplete' => $isIncomplete, 'coverage' => $this->actuals->coverage($channels, $ranges, $start, $end, $isIncomplete)];
        }
        ['windows' => $windows, 'first' => $first] = $this->actuals->comparisonWindows($unit, $periods, $channels, $ranges);

        // 店舗 => 日付 => [販売数, 金額]。期間と比べる期間の両方を1回で読む
        $loadFrom = $first === null ? $periods[0]['start'] : $first->min($periods[0]['start']);
        $daily = $this->daily($loadFrom, $today, $skuIds);

        $stores = [];
        foreach (Mall::cases() as $mall) {
            $channel = $mall->channel();
            $sold = $daily[$mall->value] ?? [];
            $store = ['coverage' => [], 'quantities' => [], 'amounts' => [], 'previous' => ['quantities' => [], 'amounts' => []], 'last_year' => ['quantities' => [], 'amounts' => []]];
            foreach ($periods as $index => $period) {
                $coverage = isset($ranges[$channel->value]) ? $this->actuals->coverage([$channel], $ranges, $period['start'], $period['end'], $period['isIncomplete']) : Coverage::None;
                $total = $coverage === Coverage::None ? null : $this->sum($sold, $period['start'], $period['end']);
                $store['coverage'][] = $coverage;
                $store['quantities'][] = $total[0] ?? null;
                $store['amounts'][] = $total[1] ?? null;
                foreach (['previous', 'last_year'] as $kind) {
                    $dates = $total === null ? null : ($windows[$index][$channel->value][$kind] ?? null);
                    $compared = $dates === null ? null : $this->sum($sold, CarbonImmutable::parse($dates[0]), CarbonImmutable::parse($dates[array_key_last($dates)]));
                    $store[$kind]['quantities'][] = $compared[0] ?? null;
                    $store[$kind]['amounts'][] = $compared[1] ?? null;
                }
            }
            $stores[$mall->value] = $store;
        }

        return [
            'periods' => array_map(fn (array $period): array => ['start' => $period['start'], 'coverage' => $period['coverage']], $periods),
            'stores' => $stores,
        ];
    }

    /**
     * 店舗・日ごとの販売数と金額。
     *
     * @param  list<int>|null  $skuIds
     * @return array<string, array<string, array{0: float, 1: float}>>
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $today, ?array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $daily = [];
        foreach ($skuIds === null ? [null] : array_chunk($skuIds, 500) as $chunk) {
            $rows = SalesLine::query()
                ->selectRaw('mall, sales_date, sum(quantity) as quantity, sum(amount) as amount')
                ->when($chunk !== null, fn ($query) => $query->whereIn('sku_id', $chunk))
                ->where('sales_date', '>=', $from->toDateString())
                ->where('sales_date', '<', $today->addDay()->toDateString())
                ->groupBy('mall', 'sales_date')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $date = CarbonImmutable::parse($row->sales_date)->toDateString();
                $current = $daily[(string) $row->mall][$date] ?? [0.0, 0.0];
                $daily[(string) $row->mall][$date] = [$current[0] + (float) $row->quantity, $current[1] + (float) $row->amount];
            }
        }

        return $daily;
    }

    /**
     * start～end の合計。
     *
     * @param  array<string, array{0: float, 1: float}>  $sold
     * @return array{0: float, 1: float}
     */
    private function sum(array $sold, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $total = [0.0, 0.0];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (isset($sold[$day->toDateString()])) {
                $total = [$total[0] + $sold[$day->toDateString()][0], $total[1] + $sold[$day->toDateString()][1]];
            }
        }

        return $total;
    }
}
