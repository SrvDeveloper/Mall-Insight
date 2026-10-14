<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesEvent;
use App\Models\SalesEventPeriod;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastTimelineControllerTest extends TestCase
{
    use RefreshDatabase;

    private Sku $sku;

    private Sku $amazonSku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        // 今日は 2026-10-07。販売実績は前日（10-06）まで
        $this->travelTo('2026-10-07 09:00:00');

        // Amazon・BOSS の対象品番（全体はその2つを合わせたもの、K-130）
        $selections = [ItemSelection::create(['scope' => 'amazon', 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']), ItemSelection::create(['scope' => 'boss', 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07'])];
        $item = Item::factory()->create(['item_no' => 'fl-01', 'brand' => 'FEELLIFE', 'category' => '老眼鏡']);
        foreach ($selections as $selection) {
            $selection->items()->create(['item_no' => 'fl-01', 'sales_rank' => 1, 'is_candidate' => true]);
        }
        $this->sku = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => 'fl-01-1', 'child_asin' => null, 'tq_color_no' => '1', 'tq_size' => '15']);
        // Amazon でも売るが、Amazon の販売実績はまだ取り込んでいない
        $this->amazonSku = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => 'fl-01-2', 'child_asin' => 'B000000001']);

        // 3月から毎日2個、楽天スーパーセール（6月・9月の4〜7日）は1日12個（6倍）売れる
        $sale = SalesEvent::factory()->create();
        SalesEventPeriod::factory()->for($sale)->period('2026-06-04', '2026-06-07')->create();
        SalesEventPeriod::factory()->for($sale)->period('2026-09-04', '2026-09-07')->create();
        SalesEventPeriod::factory()->for($sale)->period('2026-11-04', '2026-11-07')->create();
        $isSale = fn (string $date): bool => ($date >= '2026-06-04' && $date <= '2026-06-07') || ($date >= '2026-09-04' && $date <= '2026-09-07');
        $this->sellDaily('2026-03-01', '2026-10-06', fn (string $date): int => $isSale($date) ? 12 : 2);
    }

    /**
     * @param  callable(string): int  $quantity
     */
    private function sellDaily(string $from, string $to, callable $quantity): void
    {
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $rows[] = ['source' => 'boss', 'source_order_id' => "{$this->sku->id}-{$day->toDateString()}-".count($rows), 'sales_date' => $day->toDateString(), 'mall' => 'rakuten', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => $quantity($day->toDateString()), 'amount' => 1000 * $quantity($day->toDateString())];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            SalesLine::insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function boss(array $json, string $skuCode = 'fl-01-1'): array
    {
        $sku = collect($json['data'][0]['skus'])->firstWhere('sku_code', $skuCode);

        return $sku['channels']['boss'];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function at(array $json, array $values, array $keys): array
    {
        $index = array_flip(array_column($json['meta']['columns'], 'key'));

        // JSON では 60.0 が 60 になるため、数は小数にそろえて比べる（null と 0 は区別する）
        return array_combine($keys, array_map(fn (string $key): mixed => is_int($values[$index[$key]]) ? (float) $values[$index[$key]] : $values[$index[$key]], $keys));
    }

    public function test_lines_up_monthly_actuals_with_the_forecast_made_again_at_the_start_of_each_month(): void
    {
        Inventory::create(['stock_date' => '2026-09-20', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => 0]);
        Inventory::create(['stock_date' => '2026-09-23', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => 5]);

        $json = $this->getJson('/api/v1/demand-forecasts/timeline')
            ->assertOk()
            ->assertJsonPath('meta.unit', 'month')
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('meta.columns.0', ['key' => '2025-10', 'kind' => 'past', 'days' => 31])
            ->assertJsonPath('meta.columns.12', ['key' => '2026-10', 'kind' => 'current', 'days' => 31])
            ->assertJsonPath('meta.columns.23.key', '2027-09')
            ->assertJsonPath('data.0.skus.0.color_no', '1')
            ->json();
        $boss = $this->boss($json);
        $months = ['2026-02', '2026-04', '2026-09', '2026-10', '2026-11'];

        // 販売実績を取り込む前の月は「—」。今月は前日までの販売実績
        $this->assertSame(['2026-02' => null, '2026-04' => 60.0, '2026-09' => 100.0, '2026-10' => 12.0, '2026-11' => null], $this->at($json, $boss['actual'], $months));
        $this->assertSame(12000.0, $this->at($json, $boss['actual_amount'], ['2026-10'])['2026-10']);
        // 9月1日に戻ると、平均日販は2個、9月のセール（4日間）は6月のセールから求めた6倍を掛ける：2×30＋2×5×4＝100
        $this->assertSame(['2026-02' => null, '2026-04' => 60.0, '2026-09' => 100.0, '2026-10' => null, '2026-11' => null], $this->at($json, $boss['replayed'], $months));
        // 今月は1か月分、11月はセールの4日間に6倍を掛ける
        $this->assertSame(['2026-02' => null, '2026-04' => null, '2026-09' => null, '2026-10' => 62.0, '2026-11' => 100.0], $this->at($json, $boss['forecast'], $months));
        // そのうちイベントで増やした数：11月のセールの4日間に2×5
        $this->assertSame(['2026-09' => null, '2026-10' => 0.0, '2026-11' => 40.0], $this->at($json, $boss['event'], ['2026-09', '2026-10', '2026-11']));
        // 欠品した日数。在庫の記録が無い月は分からない
        $this->assertSame(['2026-08' => null, '2026-09' => 3.0, '2026-10' => 0.0], $this->at($json, $boss['stockout'], ['2026-08', '2026-09', '2026-10']));
        // 欠品かを判定できた日数。9月は在庫の記録がある20日から、今月は前日（6日）まで
        $this->assertSame(['2026-08' => null, '2026-09' => 11.0, '2026-10' => 6.0], $this->at($json, $boss['stockout_known'], ['2026-08', '2026-09', '2026-10']));

        // Amazon の販売実績を取り込んでいなければ、販売実績も予測し直した数も「—」
        $amazon = collect($json['data'][0]['skus'])->firstWhere('sku_code', 'fl-01-2')['channels']['amazon'];
        $this->assertSame([null, null], [$this->at($json, $amazon['actual'], ['2026-09'])['2026-09'], $this->at($json, $amazon['replayed'], ['2026-09'])['2026-09']]);
    }

    public function test_makes_the_forecast_again_months_before_and_redoes_it_when_the_sales_used_change(): void
    {
        // 3か月前の1日に戻る：4月は1月1日（販売実績が無く予測できない）、9月は6月1日（まだ終わったセールが無いため倍率を掛けない）
        $json = $this->getJson('/api/v1/demand-forecasts/timeline?offset=3')->assertOk()->assertJsonPath('meta.offset', 3)->json();
        $this->assertSame(['2026-04' => null, '2026-09' => 60.0], $this->at($json, $this->boss($json)['replayed'], ['2026-04', '2026-09']));

        // 起点より前の販売実績が変わったら予測し直す（5月の販売実績が増えると、6月1日に戻った平均日販が増える）
        $this->sellDaily('2026-05-15', '2026-05-15', fn (): int => 60);
        $json = $this->getJson('/api/v1/demand-forecasts/timeline?offset=3')->json();
        $this->assertGreaterThan(60.0, $this->at($json, $this->boss($json)['replayed'], ['2026-09'])['2026-09']);

        $this->getJson('/api/v1/demand-forecasts/timeline?offset=2')->assertUnprocessable()->assertJsonValidationErrors('offset');
    }

    public function test_lines_up_days_with_the_daily_forecast_and_the_daily_share_of_the_forecast_made_again(): void
    {
        Inventory::create(['stock_date' => '2026-09-20', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => 0]);
        Inventory::create(['stock_date' => '2026-09-23', 'sku_id' => $this->sku->id, 'warehouse' => 'boss_own', 'quantity' => 5]);

        $json = $this->getJson('/api/v1/demand-forecasts/timeline?unit=day')
            ->assertOk()
            ->assertJsonPath('meta.unit', 'day')
            ->assertJsonPath('meta.columns.0', ['key' => '2026-09-07', 'kind' => 'past', 'days' => 1])
            ->assertJsonPath('meta.columns.30', ['key' => '2026-10-07', 'kind' => 'current', 'days' => 1])
            ->assertJsonPath('meta.columns.60.key', '2026-11-06')
            ->json();
        $boss = $this->boss($json);
        $days = ['2026-09-07', '2026-09-10', '2026-10-06', '2026-10-07', '2026-11-03', '2026-11-04'];

        $this->assertSame(['2026-09-07' => 12.0, '2026-09-10' => 2.0, '2026-10-06' => 2.0, '2026-10-07' => null, '2026-11-03' => null, '2026-11-04' => null], $this->at($json, $boss['actual'], $days));
        // 9月の日は9月1日に戻った予測の1日分。セールの日は6倍
        $this->assertSame(['2026-09-07' => 12.0, '2026-09-10' => 2.0, '2026-10-06' => 2.0, '2026-10-07' => null, '2026-11-03' => null, '2026-11-04' => null], $this->at($json, $boss['replayed'], $days));
        // 今日から先は日ごとの予測。11月のセールの日は6倍
        $this->assertSame(['2026-09-07' => null, '2026-09-10' => null, '2026-10-06' => null, '2026-10-07' => 2.0, '2026-11-03' => 2.0, '2026-11-04' => 12.0], $this->at($json, $boss['forecast'], $days));
        // そのうちイベントで増やした数（セールの日は普段の2個に10個を足す）
        $this->assertSame(['2026-10-06' => null, '2026-11-03' => 0.0, '2026-11-04' => 10.0], $this->at($json, $boss['event'], ['2026-10-06', '2026-11-03', '2026-11-04']));
        // 欠品したか。在庫の記録より前と、今日から先は分からない
        $stockoutDays = ['2026-09-19', '2026-09-20', '2026-09-23', '2026-10-06', '2026-10-07'];
        $this->assertSame(['2026-09-19' => null, '2026-09-20' => true, '2026-09-23' => false, '2026-10-06' => false, '2026-10-07' => null], $this->at($json, $boss['stockout'], $stockoutDays));
        $this->assertSame(['2026-09-19' => null, '2026-09-20' => 1.0, '2026-09-23' => 1.0, '2026-10-06' => 1.0, '2026-10-07' => null], $this->at($json, $boss['stockout_known'], $stockoutDays));

        $this->getJson('/api/v1/demand-forecasts/timeline?unit=day&from=2026-10-01&to=2027-01-10')->assertUnprocessable()->assertJsonPath('errors.to.0', '日ごとの予測は、今日から90日先までです。');
        $this->getJson('/api/v1/demand-forecasts/timeline?unit=day&from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_adds_the_basis_of_the_forecast_for_one_item(): void
    {
        $this->getJson('/api/v1/demand-forecasts/timeline')->assertOk()->assertJsonMissingPath('data.0.skus.0.detail');

        $this->getJson('/api/v1/demand-forecasts/timeline?item_no=fl-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.skus.0.detail.channels.0.malls.0.windows.0.label', '直近30日')
            ->assertJsonPath('data.0.skus.0.detail.monthly.1.events.0.name', '楽天スーパーセール');
    }
}
