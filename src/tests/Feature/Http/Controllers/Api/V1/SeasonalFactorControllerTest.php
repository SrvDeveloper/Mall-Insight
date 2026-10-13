<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\ForecastBacktest;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\ItemSelection;
use App\Models\SalesEventPeriod;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonalFactorControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 1月～12月。9月・12月は2倍、11月は半分 */
    private const FACTORS = [1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 0.5, 2];

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => '担当者']));
        // 今日は 2026-10-07。販売実績は前日（10-06）まで
        $this->travelTo('2026-10-07 09:00:00');
        $item = Item::factory()->create(['item_no' => 'ph5099', 'category' => '調光サングラス']);
        $this->sku = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => 'ph5099-1', 'child_asin' => null]);
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        $selection->items()->create(['item_no' => 'ph5099', 'sales_rank' => 1, 'is_candidate' => true]);
    }

    /**
     * @param  callable(CarbonImmutable): int  $quantity
     */
    private function sellDaily(string $from, string $to, callable $quantity): void
    {
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $rows[] = ['source' => 'boss', 'source_order_id' => $day->toDateString(), 'sales_date' => $day->toDateString(), 'mall' => 'rakuten', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => $quantity($day), 'amount' => 1000];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            SalesLine::insert($chunk);
        }
    }

    public function test_leaves_the_season_out_of_the_averages_and_multiplies_each_month_by_its_registered_factor(): void
    {
        // 普段は1日2個、9月は2倍の1日4個売れた
        $this->sellDaily('2026-04-01', '2026-10-06', fn (CarbonImmutable $day): int => $day->month === 9 ? 4 : 2);
        ItemSeasonalFactor::create(['item_no' => 'ph5099', 'factors' => self::FACTORS]);
        $period = SalesEventPeriod::factory()->period('2026-12-04', '2026-12-07')->create();
        $period->multipliers()->create(['item_no' => 'ph5099', 'channel' => 'boss', 'multiplier' => 3]);

        $response = $this->getJson('/api/v1/demand-forecasts')->assertOk()->assertJsonPath('meta.seasonal_item_nos', ['ph5099']);

        $channel = $response->json('data.0.skus.0.channels.0');
        // 直近30日（09-07〜10-06）：9月24日×4個＋10月6日×2個＝108個 ÷（24日×2＋6日×1）＝2個。季節係数の平均は 54÷30＝1.8
        $this->assertEqualsWithDelta(2.0, $channel['windows'][0]['average'], 0.0001);
        $this->assertEqualsWithDelta(1.8, $channel['windows'][0]['seasonal_factor'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $channel['base_average'], 0.0001);
        $months = collect($channel['monthly'])->keyBy('month');
        $this->assertEqualsWithDelta(62, $months['2026-10']['quantity'], 0.01);
        $this->assertEqualsWithDelta(30, $months['2026-11']['quantity'], 0.01);
        $this->assertSame(0.5, $months['2026-11']['seasonal_factor']);
        // 12月：2個×2倍×31日 ＋ イベントの4日に 2個×2倍×（3倍－1）
        $this->assertEqualsWithDelta(124 + 32, $months['2026-12']['quantity'], 0.01);
        $this->assertEqualsWithDelta(32, $months['2026-12']['event_quantity'], 0.01);
        $this->assertSame(2, $response->json('data.0.skus.0.monthly.2.seasonal_factor'));

        // 登録をやめると、季節による増減を掛けない
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => null])->assertOk()->assertJsonPath('data.current.factors', null);
        $november = collect($this->getJson('/api/v1/demand-forecasts')->json('data.0.skus.0.monthly'))->firstWhere('month', '2026-11');
        $this->assertNull($november['seasonal_factor']);
    }

    public function test_offers_the_factors_found_in_past_sales_as_the_initial_values_and_keeps_every_registration(): void
    {
        // 2023年7月から、普段は1日2個、12月だけ1日4個。どの月も、前後12か月の移動平均は（11×2＋4）÷12
        $this->sellDaily('2023-07-01', '2026-10-06', fn (CarbonImmutable $day): int => $day->month === 12 ? 4 : 2);

        $this->getJson('/api/v1/seasonal-factors')
            ->assertOk()
            ->assertJsonPath('data.0.item_no', 'ph5099')
            ->assertJsonPath('data.0.current', null)
            ->assertJsonPath('data.0.initial.source', 'item')
            ->assertJsonPath('data.0.initial.factors', [0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 0.92, 1.85])
            ->assertJsonPath('meta.min_factor', 0.1);

        $detail = $this->getJson('/api/v1/seasonal-factors/ph5099')->assertOk();
        // 品番・カテゴリー・全体の3つの単位（対象品番が1つなので同じ値）
        $this->assertSame(['item', 'category', 'overall'], array_column($detail->json('data.levels'), 'source'));
        $this->assertSame('調光サングラス', $detail->json('data.levels.1.source_label'));
        // 年ごとの月の販売数。取り込んでいない月（2023年1〜6月）と、これからの月は null
        $sales = collect($detail->json('data.sales'))->keyBy('year');
        $this->assertSame([null, null, null, null, null, null, 62, 62, 60, 62, 60, 124], $sales[2023]['months']);
        $this->assertSame(12, $sales[2026]['months'][9]);
        $this->assertNull($sales[2026]['months'][10]);

        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => self::FACTORS, 'note' => '5月は欠品のため1にした'])
            ->assertOk()
            ->assertJsonPath('data.current.factors', [1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 0.5, 2])
            ->assertJsonPath('data.current.initial_source', 'item')
            ->assertJsonPath('data.current.initial_factors.11', 1.85)
            ->assertJsonPath('data.current.note', '5月は欠品のため1にした')
            ->assertJsonPath('data.current.changed_by', '担当者');
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => array_fill(0, 12, 1.2)])->assertOk()->assertJsonCount(2, 'data.history');
        $this->assertSame(2, ItemSeasonalFactor::query()->count());
    }

    public function test_takes_twelve_factors_between_0_1_and_5_with_up_to_two_decimals(): void
    {
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => array_fill(0, 11, 1)])->assertUnprocessable()->assertJsonValidationErrors('factors');
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => [0.05, ...array_fill(0, 11, 1)]])->assertUnprocessable()->assertJsonValidationErrors('factors.0');
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => [1.234, ...array_fill(0, 11, 1)]])->assertUnprocessable()->assertJsonValidationErrors('factors.0');
        $this->putJson('/api/v1/seasonal-factors/ph5099', ['factors' => [6, ...array_fill(0, 11, 1)]])->assertUnprocessable()->assertJsonValidationErrors('factors.0');
        $this->putJson('/api/v1/seasonal-factors/unknown', ['factors' => null])->assertNotFound();
    }

    public function test_the_backtest_compares_the_registered_factors_with_events_only(): void
    {
        // 普段は1日2個、12月だけ1日4個。12月を2倍と登録すれば12月も当たる
        $this->sellDaily('2024-07-01', '2026-10-06', fn (CarbonImmutable $day): int => $day->month === 12 ? 4 : 2);
        ItemSeasonalFactor::create(['item_no' => 'ph5099', 'factors' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2]]);

        $id = $this->postJson('/api/v1/forecast-backtests')->assertCreated()->json('data.id');
        $methods = collect($this->getJson("/api/v1/forecast-backtests/{$id}")->json('data.methods'))->keyBy('method');

        $this->assertSame(0.0, (float) $methods['seasonal']['wape']);
        $this->assertGreaterThan(0.01, $methods['event_adjusted']['wape']);
        $this->assertSame(['ph5099' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 2]], array_map(fn (array $factors): array => array_map('intval', $factors), ForecastBacktest::query()->find($id)->settings['seasonal_factors']));
    }
}
