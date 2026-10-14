<?php

namespace App\Services\Forecast;

use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\ItemSelection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 季節係数の画面（B-106、K-113）に出す値。品番ごとの登録した係数と、初期値にする販売実績から求めた係数（品番・カテゴリー・全体）、
 * 係数を決める手がかりにする年・月ごとの販売数。
 */
class SeasonalFactorReport
{
    public function __construct(private DemandForecaster $forecaster, private EventUpliftEstimator $events, private SeasonalIndexEstimator $estimator) {}

    /**
     * 対象品番（選定の順）の、登録した係数と初期値。
     *
     * @return list<array<string, mixed>>
     */
    public function overview(CarbonImmutable $today): array
    {
        $items = $this->targetItems();
        $levels = $this->levels($items, $today);
        $records = ItemSeasonalFactor::latestByItem($items->pluck('item_no')->all());

        return $items->map(fn (Item $item): array => [
            'item_no' => $item->item_no,
            'brand' => $item->brand,
            'category' => $item->category,
            'current' => $this->record($records[$item->item_no] ?? null),
            'initial' => $this->index($this->initial($levels[$item->item_no] ?? [])),
        ])->values()->all();
    }

    /**
     * 1つの品番の、登録した係数・初期値・3つの単位の係数・年と月ごとの販売数・登録の履歴。
     *
     * @return array<string, mixed>
     */
    public function detail(Item $item, CarbonImmutable $today): array
    {
        $items = $this->targetItems();
        if (! $items->contains(fn (Item $target): bool => $target->item_no === $item->item_no)) {
            $items = $items->push($item->load(['skus']));
        }
        $levels = $this->levels($items, $today)[$item->item_no] ?? [];
        $history = ItemSeasonalFactor::query()->with('changedBy')->where('item_no', $item->item_no)->orderByDesc('id')->limit(10)->get();

        return [
            'item_no' => $item->item_no,
            'brand' => $item->brand,
            'category' => $item->category,
            'is_target' => $this->targetItems()->contains(fn (Item $target): bool => $target->item_no === $item->item_no),
            'current' => $this->record($history->first()),
            'initial' => $this->index($this->initial($levels)),
            'levels' => array_map(fn (SeasonalIndex $index): array => $this->index($index), $levels),
            'sales' => $this->monthlySales($item, $today),
            'history' => $history->map(fn (ItemSeasonalFactor $record): array => $this->record($record))->all(),
        ];
    }

    /**
     * 初期値にする係数（品番・カテゴリー・全体のうち、使えるもののいちばん細かい単位）。
     *
     * @param  list<SeasonalIndex>  $levels
     */
    public function initial(array $levels): ?SeasonalIndex
    {
        return collect($levels)->first(fn (SeasonalIndex $index): bool => $index->isAvailable() && $index->monthlyQuantity >= SeasonalIndexEstimator::MIN_MONTHLY_QUANTITY);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array<string, list<SeasonalIndex>>
     */
    public function levels(Collection $items, CarbonImmutable $today): array
    {
        return $this->estimator->levels($items, $this->forecaster->channelSalesRanges($today), $this->events->channelEventDates($this->events->periods()), $today);
    }

    /**
     * 年（1月〜12月）・月ごとの販売数（全チャネル、イベントの日も含む）。販売実績をどのチャネルでも取り込んでいない月は null。
     *
     * @return list<array{year: int, months: list<int|null>}>
     */
    private function monthlySales(Item $item, CarbonImmutable $today): array
    {
        $ranges = array_values(array_filter($this->forecaster->channelSalesRanges($today), fn (array $range): bool => $range['from'] !== null));
        if ($ranges === []) {
            return [];
        }
        $from = min(array_map(fn (array $range): CarbonImmutable => $range['from'], $ranges));
        $daily = $this->estimator->loadDailySales(collect([$item->loadMissing('skus')]), $today->subDay())[$item->item_no] ?? [];
        $totals = [];
        foreach ($daily as $byDate) {
            foreach ($byDate as $date => $quantity) {
                $month = substr($date, 0, 7);
                $totals[$month] = ($totals[$month] ?? 0) + $quantity;
            }
        }
        $isImported = function (string $month) use ($ranges): bool {
            foreach ($ranges as $range) {
                if ($range['from']->format('Y-m') <= $month && $range['to']->format('Y-m') >= $month) {
                    return true;
                }
            }

            return false;
        };

        $rows = [];
        for ($year = $from->year; $year <= $today->year; $year++) {
            $months = [];
            for ($month = 1; $month <= 12; $month++) {
                $key = sprintf('%04d-%02d', $year, $month);
                $months[] = $key > $today->format('Y-m') || ! $isImported($key) ? null : ($totals[$key] ?? 0);
            }
            $rows[] = ['year' => $year, 'months' => $months];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function record(?ItemSeasonalFactor $record): ?array
    {
        return $record === null ? null : [
            'id' => $record->id,
            'factors' => $record->factorsByMonth() === null ? null : array_values($record->factorsByMonth()),
            'initial_factors' => $record->initial_factors === null ? null : array_map(fn ($factor): float => (float) $factor, array_values($record->initial_factors)),
            'initial_source' => $record->initial_source,
            'note' => $record->note,
            'changed_by' => $record->changedBy?->name,
            'changed_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function index(?SeasonalIndex $index): ?array
    {
        return $index === null ? null : [
            'source' => $index->source,
            'source_label' => $index->sourceLabel,
            'factors' => $index->factors === null ? null : array_map(fn (float $factor): float => round($factor, 2), array_values($index->factors)),
            'years' => array_values($index->years),
            'monthly_quantity' => round($index->monthlyQuantity, 1),
            'is_usable' => $index->isAvailable() && $index->monthlyQuantity >= SeasonalIndexEstimator::MIN_MONTHLY_QUANTITY,
        ];
    }

    /**
     * @return Collection<int, Item>
     */
    private function targetItems(): Collection
    {
        return $this->forecaster->items(collect(ItemSelection::current()?->items->pluck('item_no')->all() ?? []));
    }
}
