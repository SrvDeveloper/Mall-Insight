<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\ForecastBacktest;
use App\Models\ForecastBacktestResult;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesEvent;
use App\Models\SalesEventPeriod;
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
        // Amazon・BOSS の対象品番（全体はその2つを合わせたもの、K-130）
        $selections = [ItemSelection::create(['scope' => 'amazon', 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']), ItemSelection::create(['scope' => 'boss', 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07'])];
        foreach (['fl-01', 'si-13'] as $rank => $itemNo) {
            Item::factory()->create(['item_no' => $itemNo]);
            foreach ($selections as $selection) {
                $selection->items()->create(['item_no' => $itemNo, 'sales_rank' => $rank + 1, 'is_candidate' => true]);
            }
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
        foreach (['seasonal', 'event_adjusted', 'base_average', 'recent_30', 'last_year'] as $method) {
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
        $this->assertSame('今の方式（イベント＋季節係数）', $methods['seasonal']['label']);
        $this->assertSame('イベントの補正だけ', $methods['event_adjusted']['label']);
        $this->assertSame('基準平均日販（補正なし）', $methods['base_average']['label']);
        // イベントと季節係数を登録していなければ、今の方式は補正なしと同じ
        $this->assertEqualsWithDelta($methods['base_average']['forecast'], $methods['event_adjusted']['forecast'], 0.001);
        $this->assertEqualsWithDelta($methods['base_average']['forecast'], $methods['seasonal']['forecast'], 0.001);
        $this->assertEqualsWithDelta(365.0, $methods['base_average']['actual'], 0.001);
        $this->assertEqualsWithDelta(309.36 / 365, $methods['base_average']['wape'], 0.0001);
        $this->assertEqualsWithDelta(309.36 / 365, $methods['base_average']['bias'], 0.0001);
        $this->assertEqualsWithDelta(310 / 365, $methods['recent_30']['wape'], 0.0001);
        $this->assertSame(0.0, (float) $methods['last_year']['wape']);
        // 品番が1SKUずつなので、品番の合計で比べても同じ
        $this->assertEqualsWithDelta($methods['base_average']['wape'], $methods['base_average']['item_wape'], 0.0001);
        // イベントを登録していなければ、すべて普段の日
        $this->assertSame(0.0, (float) $methods['base_average']['event']['actual']);
        $this->assertNull($methods['base_average']['event']['wape']);
        $this->assertEqualsWithDelta($methods['base_average']['wape'], $methods['base_average']['normal']['wape'], 0.0001);

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

        // イベントの日と普段の日に分ける前に測った記録では、分けた値を出さない
        ForecastBacktestResult::query()->update(['event_actual' => null, 'event_forecast' => null, 'event_abs_error' => null, 'normal_actual' => null, 'normal_forecast' => null, 'normal_abs_error' => null]);
        $this->getJson("/api/v1/forecast-backtests/{$id}")->assertJsonPath('data.methods.0.event', null)->assertJsonPath('data.methods.0.normal', null);
    }

    public function test_the_current_method_leaves_out_event_days_and_multiplies_them_by_the_candidate_known_at_each_origin(): void
    {
        // 普段は毎日1個、楽天スーパーセール（3・6・9・12月の4〜7日）は毎日5個売れる
        $sku = Sku::factory()->create(['item_id' => Item::query()->where('item_no', 'fl-01')->value('id'), 'sku_code' => 'fl-01-1-10', 'child_asin' => null]);
        $isSale = fn (CarbonImmutable $day): bool => in_array($day->month, [3, 6, 9, 12], true) && $day->day >= 4 && $day->day <= 7;
        $this->sellDaily($sku, '2024-07-01', '2026-10-06', fn (CarbonImmutable $day): int => $isSale($day) ? 5 : 1);
        $sale = SalesEvent::factory()->create();
        foreach (['2024-09', '2024-12', '2025-03', '2025-06', '2025-09', '2025-12', '2026-03', '2026-06', '2026-09'] as $month) {
            SalesEventPeriod::factory()->for($sale)->period("{$month}-04", "{$month}-07")->create();
        }

        $id = $this->postJson('/api/v1/forecast-backtests')->assertCreated()->json('data.id');
        $this->assertEquals(['event_recent_events' => 3, 'event_min_expected' => 10], array_intersect_key(ForecastBacktest::query()->find($id)->settings, array_flip(['event_recent_events', 'event_min_expected'])));
        $this->assertCount(9, ForecastBacktest::query()->find($id)->settings['events']);

        $methods = collect($this->getJson("/api/v1/forecast-backtests/{$id}")->json('data.methods'))->keyBy('method');
        // セールの日を除いた平均日販は1個、セールは過去のセールから5倍と分かるため、どの月も当たる
        $this->assertSame(0.0, (float) $methods['event_adjusted']['wape']);
        // 補正しないと、セールの月は少なめ、セールの後の月は多めに外れる
        $this->assertGreaterThan(0.05, $methods['base_average']['wape']);
        $this->assertSame($methods['base_average']['evaluated'], $methods['event_adjusted']['evaluated']);

        // イベントの日と普段の日に分ける。答え合わせの月（2025年10月～2026年9月）のセールは4回・16日で、毎日5個
        foreach (['seasonal', 'event_adjusted', 'base_average'] as $method) {
            $this->assertEqualsWithDelta(80.0, $methods[$method]['event']['actual'], 0.001);
            $this->assertEqualsWithDelta($methods[$method]['actual'] - 80.0, $methods[$method]['normal']['actual'], 0.001);
        }
        // イベントの補正は、イベントの日も普段の日も当たる
        $this->assertSame(0.0, (float) $methods['event_adjusted']['event']['wape']);
        $this->assertSame(0.0, (float) $methods['event_adjusted']['normal']['wape']);
        // 補正しないと、イベントの日は少なめ、普段の日は（セールの日を平均に入れるため）多めになる
        $this->assertLessThan(-0.5, $methods['base_average']['event']['bias']);
        $this->assertGreaterThan(0.0, $methods['base_average']['normal']['bias']);
    }

    public function test_measures_each_channel_with_its_own_target_items(): void
    {
        // fl-01・si-13 とも BOSS と Amazon で毎日1個売れる。Amazon の対象品番は si-13 だけ
        foreach (['fl-01', 'si-13'] as $itemNo) {
            $sku = Sku::factory()->create(['item_id' => Item::query()->where('item_no', $itemNo)->value('id'), 'sku_code' => "{$itemNo}-1", 'child_asin' => "B-{$itemNo}"]);
            $this->sellDaily($sku, '2024-07-01', '2026-10-06', fn (): int => 1);
            $rows = [];
            for ($day = CarbonImmutable::parse('2024-07-01'); $day->lte(CarbonImmutable::parse('2026-10-06')); $day = $day->addDay()) {
                $rows[] = ['source' => 'amazon', 'source_order_id' => "a-{$sku->id}-{$day->toDateString()}", 'sales_date' => $day->toDateString(), 'mall' => 'amazon', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 1, 'amount' => 1000];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                SalesLine::insert($chunk);
            }
        }
        $amazon = ItemSelection::create(['scope' => 'amazon', 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        $amazon->items()->create(['item_no' => 'si-13', 'sales_rank' => 1, 'is_candidate' => true]);

        $id = $this->postJson('/api/v1/forecast-backtests')->assertCreated()->json('data.id');
        $this->assertSame(['total' => ['si-13', 'fl-01'], 'amazon' => ['si-13'], 'boss' => ['fl-01', 'si-13']], ForecastBacktest::query()->find($id)->settings['target_items']);
        $this->getJson("/api/v1/forecast-backtests/{$id}?channel=amazon")->assertJsonPath('data.items.*.item_no', ['si-13']);
        $this->getJson("/api/v1/forecast-backtests/{$id}?channel=boss")->assertJsonCount(2, 'data.items');
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
