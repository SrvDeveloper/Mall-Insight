<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\ForecastBacktest;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ForecastBacktestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '担当者']);
        $this->actingAs($this->user);
        // 今日は 2026-10-07。答え合わせできる最新の月は 2026年9月
        $this->travelTo('2026-10-07 09:00:00');
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        foreach (['fl-01', 'si-13'] as $rank => $itemNo) {
            Item::factory()->create(['item_no' => $itemNo]);
            $selection->items()->create(['item_no' => $itemNo, 'sales_rank' => $rank + 1, 'is_candidate' => true]);
        }
    }

    /**
     * BOSS の販売実績を1日ずつ入れる。
     *
     * @param  callable(CarbonImmutable): int  $quantity
     */
    private function sellDaily(Sku $sku, string $from, string $to, callable $quantity): void
    {
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $count = $quantity($day);
            if ($count > 0) {
                $rows[] = ['source' => 'boss', 'source_order_id' => "{$sku->id}-{$day->toDateString()}", 'sales_date' => $day->toDateString(), 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => $count, 'amount' => 1000 * $count];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            SalesLine::insert($chunk);
        }
    }

    public function test_measures_each_method_against_the_sales_after_each_origin(): void
    {
        // fl-01-1-10 は毎日1個ずつ売れる。si-13-1-0 は12月だけ毎日10個売れる（Amazon では売っていない）
        $steady = Sku::factory()->create(['item_id' => Item::query()->where('item_no', 'fl-01')->value('id'), 'sku_code' => 'fl-01-1-10', 'child_asin' => null]);
        $seasonal = Sku::factory()->create(['item_id' => Item::query()->where('item_no', 'si-13')->value('id'), 'sku_code' => 'si-13-1-0', 'child_asin' => null]);
        $this->sellDaily($steady, '2023-07-01', '2026-10-06', fn (): int => 1);
        $this->sellDaily($seasonal, '2024-12-01', '2026-10-06', fn (CarbonImmutable $day): int => $day->month === 12 ? 10 : 0);

        $id = $this->postJson('/api/v1/forecast-backtests')
            ->assertCreated()
            ->assertJsonPath('data.measured_by', '担当者')
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.sku_count', 2)
            // 何か月先ごとに、答え合わせできる新しい方から12の起点
            ->assertJsonPath('data.horizons', [
                ['horizon' => 'month_1', 'label' => '1か月目', 'origin_from' => '2025-10-01', 'origin_to' => '2026-09-01', 'origin_count' => 12],
                ['horizon' => 'months_3', 'label' => '1～3か月目の合計', 'origin_from' => '2025-08-01', 'origin_to' => '2026-07-01', 'origin_count' => 12],
                ['horizon' => 'check_month', 'label' => '6か月後（判定する月）', 'origin_from' => '2025-04-01', 'origin_to' => '2026-03-01', 'origin_count' => 12],
            ])
            ->json('data.id');

        $response = $this->getJson("/api/v1/forecast-backtests/{$id}")->assertOk()->assertJsonPath('meta.horizon', 'month_1')->assertJsonPath('meta.channel', 'total');

        // 毎日1個の品番は、どの方式でも当たる
        $items = collect($response->json('data.items'))->keyBy('item_no');
        foreach (['base_average', 'recent_30', 'last_year'] as $method) {
            $this->assertSame(0.0, (float) $items['fl-01']['methods'][$method]['wape']);
            $this->assertSame(12, $items['fl-01']['methods'][$method]['evaluated']);
        }

        // 12月だけ売れる品番は、今の方式で予測できる 2026年1月～6月の起点だけを比べる（それ以外は比べない）。販売実績は0のため WAPE は出さない
        $seasonalBase = $items['si-13']['methods']['base_average'];
        $this->assertSame(6, $seasonalBase['evaluated']);
        $this->assertSame(6, $seasonalBase['skipped']);
        $this->assertNull($seasonalBase['wape']);
        // 1月 31×5.05、2月 28×1.55、3月 31×1.55、4月 30×0.6889、5月 31×0.6889、6月 30×0.6444
        $this->assertEqualsWithDelta(309.36, $seasonalBase['forecast'], 0.01);
        // 直近30日の平均は 1月だけ 31×10。前年同月の実績（2025年1月～6月）は0で当たる
        $this->assertEqualsWithDelta(310.0, $items['si-13']['methods']['recent_30']['forecast'], 0.01);
        $this->assertSame(0.0, (float) $items['si-13']['methods']['last_year']['forecast']);

        // 全体：販売実績は毎日1個の品番の 365個。WAPE は差の絶対値の合計 ÷ 販売実績
        $methods = collect($response->json('data.methods'))->keyBy('method');
        $this->assertSame('今の方式（基準平均日販）', $methods['base_average']['label']);
        $this->assertEqualsWithDelta(365.0, $methods['base_average']['actual'], 0.001);
        $this->assertEqualsWithDelta(309.36 / 365, $methods['base_average']['wape'], 0.0001);
        $this->assertEqualsWithDelta(309.36 / 365, $methods['base_average']['bias'], 0.0001);
        $this->assertEqualsWithDelta(310 / 365, $methods['recent_30']['wape'], 0.0001);
        $this->assertSame(0.0, (float) $methods['last_year']['wape']);

        // 起点ごと
        $origins = collect($response->json('data.origins'))->keyBy('origin');
        $this->assertCount(12, $origins);
        $this->assertEqualsWithDelta(31 * 5.05 / 31, $origins['2026-01-01']['methods']['base_average']['wape'], 0.0001);
        $this->assertSame(0.0, (float) $origins['2025-10-01']['methods']['base_average']['wape']);

        // 何か月先とチャネルで絞る。Amazon では売っていないため、比べたものは無い
        $this->getJson("/api/v1/forecast-backtests/{$id}?horizon=months_3")->assertJsonPath('meta.horizon', 'months_3')->assertJsonPath('data.methods.0.evaluated', 18);
        $this->getJson("/api/v1/forecast-backtests/{$id}?channel=amazon")->assertJsonPath('data.methods.0.evaluated', 0)->assertJsonPath('data.methods.0.wape', null);
        $this->getJson("/api/v1/forecast-backtests/{$id}?horizon=month_9")->assertUnprocessable()->assertJsonValidationErrors('horizon');

        $this->getJson('/api/v1/forecast-backtests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_keeps_the_latest_twenty_measurements(): void
    {
        foreach (range(1, 20) as $number) {
            ForecastBacktest::create(['measured_at' => now()->subDays($number), 'check_month_offset' => 6, 'settings' => [], 'item_count' => 0, 'sku_count' => 0, 'duration_ms' => 0]);
        }
        $oldest = ForecastBacktest::query()->min('id');

        $this->postJson('/api/v1/forecast-backtests')->assertCreated();

        $this->assertSame(20, ForecastBacktest::query()->count());
        $this->assertNull(ForecastBacktest::query()->find($oldest));
    }

    public function test_does_not_measure_twice_at_the_same_time(): void
    {
        $lock = Cache::lock('forecast-backtest', 300);
        $lock->get();

        $this->postJson('/api/v1/forecast-backtests')->assertStatus(409)->assertJsonPath('message', '予測精度を測っています。終わるまでお待ちください。');

        $lock->release();
    }
}
