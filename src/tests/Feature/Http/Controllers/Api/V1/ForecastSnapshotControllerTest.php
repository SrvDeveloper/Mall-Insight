<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Models\ForecastAdjustment;
use App\Models\ForecastSnapshot;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastSnapshotControllerTest extends TestCase
{
    use RefreshDatabase;

    private Sku $first;

    private Sku $second;

    private Sku $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => '担当者']));
        $this->travelTo('2026-10-07 09:00:00');
        $item = Item::factory()->create(['item_no' => 'fl-01']);
        $other = Item::factory()->create(['item_no' => 'si-13']);
        foreach (['amazon', 'boss'] as $scope) {
            ItemSelection::create(['scope' => $scope, 'confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07'])->items()->create(['item_no' => 'fl-01', 'sales_rank' => 1, 'is_candidate' => true]);
        }
        $this->first = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => 'fl-01-1', 'child_asin' => 'B000000001']);
        $this->second = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => 'fl-01-2', 'child_asin' => null]);
        $this->other = Sku::factory()->create(['item_id' => $other->id, 'sku_code' => 'si-13-1']);
    }

    private function sell(Sku $sku, string $date, int $quantity, string $warehouse = 'boss_own'): void
    {
        static $orderId = 1;

        $mall = str_starts_with($warehouse, 'amazon') ? 'amazon' : 'rakuten';
        SalesLine::create(['source' => $mall === 'amazon' ? 'amazon' : 'boss', 'source_order_id' => (string) $orderId++, 'sales_date' => $date, 'mall' => $mall, 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity, 'amount' => 1000 * $quantity]);
    }

    public function test_saves_the_forecast_after_and_before_the_manual_adjustment_with_its_conditions(): void
    {
        // BOSS は直近30日に毎日2個で、その前は売れていない（基準平均日販は 2×0.5＝1）。Amazon の販売実績はまだ無い
        foreach (range(1, 30) as $day) {
            $this->sell($this->first, now()->subDays($day)->toDateString(), 2);
        }
        $this->sell($this->first, '2026-03-01', 0);
        ForecastAdjustment::factory()->adjusting('fl-01', Channel::Boss, '2026-11', '2026-11', 150)->create();

        $this->postJson('/api/v1/forecast-snapshots')->assertCreated()->assertJsonPath('data.item_count', 1);

        $snapshot = ForecastSnapshot::query()->sole();
        $this->assertSame('manual', $snapshot->trigger->value);
        $this->assertSame('2026-10-07', $snapshot->forecast_from->toDateString());
        $this->assertSame(6, $snapshot->check_month_offset);
        $this->assertSame(['channel' => 'boss', 'from' => '2026-03-01', 'to' => '2026-10-06'], $snapshot->channels[0]);
        $this->assertSame(['fl-01'], $snapshot->target_items['boss']);
        $this->assertSame([['id' => 1, 'item_no' => 'fl-01', 'channel' => 'boss', 'starts_on' => '2026-11', 'ends_on' => '2026-11', 'rate_percent' => 150]], $snapshot->adjustments);

        // SKU・チャネル・12か月ごと。11月は補正の後 1×30×1.5＝45、補正の前 30
        $november = $snapshot->values()->where('sku_id', $this->first->id)->where('channel', 'boss')->whereDate('month', '2026-11-01')->sole();
        $this->assertSame([45.0, 30.0, 1.5, 1.0], [$november->quantity, $november->unadjusted_quantity, $november->adjustment_rate, $november->base_average]);
        $this->assertSame(12, $snapshot->values()->where('sku_id', $this->first->id)->where('channel', 'boss')->count());
        // 予測できないSKUは数量を null にして理由を残す
        $this->assertSame('no_sales', $snapshot->values()->where('sku_id', $this->second->id)->first()->unpredictable_reason);
    }

    public function test_saves_once_a_month_automatically_unless_forced(): void
    {
        $this->sell($this->first, '2026-10-01', 1);

        $this->artisan('forecasts:snapshot')->assertSuccessful();
        $this->artisan('forecasts:snapshot')->expectsOutput('今月の自動保存の版はもう保存してあります。')->assertSuccessful();
        $this->assertSame(1, ForecastSnapshot::query()->where('trigger', 'schedule')->count());

        $this->artisan('forecasts:snapshot --force')->assertSuccessful();
        $this->assertSame(2, ForecastSnapshot::query()->count());
    }

    public function test_compares_the_saved_forecast_with_the_actual_sales_after_and_before_the_adjustment(): void
    {
        // BOSS は10月6日まで、Amazon は8月末まで販売実績を取り込んである
        $this->sell($this->first, '2026-09-10', 100);
        $this->sell($this->second, '2026-09-10', 80);
        $this->sell($this->other, '2026-09-10', 999);
        $this->sell($this->first, '2026-10-06', 1);
        $this->sell($this->first, '2026-08-31', 5, 'amazon_fba');

        $snapshot = ForecastSnapshot::create([
            'taken_at' => '2026-09-01 23:30:00', 'forecast_from' => '2026-09-01', 'trigger' => 'schedule', 'check_month_offset' => 6,
            'channels' => [], 'target_items' => ['total' => ['fl-01', 'si-13'], 'amazon' => ['fl-01'], 'boss' => ['fl-01']], 'adjustments' => [], 'seasonal_item_nos' => [], 'item_count' => 2, 'sku_count' => 3,
        ]);
        $value = fn (Sku $sku, string $itemNo, string $channel, float $quantity, float $unadjusted, ?float $rate = null): array => ['item_no' => $itemNo, 'sku_id' => $sku->id, 'sku_code' => $sku->sku_code, 'channel' => $channel, 'month' => '2026-09-01', 'quantity' => $quantity, 'unadjusted_quantity' => $unadjusted, 'event_quantity' => 0, 'adjustment_rate' => $rate, 'base_average' => 1];
        $snapshot->values()->createMany([
            // 1つ目のSKUは1.2倍に補正して120（補正の前は100で当たっていた）、2つ目は50
            $value($this->first, 'fl-01', 'boss', 120, 100, 1.2),
            $value($this->second, 'fl-01', 'boss', 50, 50),
            // BOSS の対象品番でない品番は比べない
            $value($this->other, 'si-13', 'boss', 1, 1),
            $value($this->first, 'fl-01', 'amazon', 40, 40),
        ]);

        $response = $this->getJson('/api/v1/forecast-snapshots')->assertOk()->assertJsonPath('data.0.trigger_label', '毎月1日の自動保存');
        // 9月の BOSS：実績 180、予測 170。外れ（20＋30）÷180、品番の合計で比べると |170−180|÷180
        $response->assertJsonPath('data.0.evaluations.month_1.months', ['2026-09'])
            ->assertJsonPath('data.0.evaluations.month_1.channels.boss', [
                'evaluated' => 2, 'adjusted' => 1, 'actual' => 180, 'forecast' => 170,
                'wape' => 0.2778, 'item_wape' => 0.0556, 'bias' => -0.0556,
                'unadjusted_forecast' => 150, 'unadjusted_wape' => 0.1667, 'unadjusted_item_wape' => 0.1667, 'unadjusted_bias' => -0.1667,
            ])
            // Amazon は9月の販売実績をまだ取り込んでいないため答え合わせできず、全体も出さない
            ->assertJsonPath('data.0.evaluations.month_1.channels.amazon', null)
            ->assertJsonPath('data.0.evaluations.month_1.channels.total', null)
            // 1～3か月目（9月～11月）は、12月から答え合わせできる
            ->assertJsonPath('data.0.evaluations.months_3.channels.boss', null)
            ->assertJsonPath('data.0.evaluations.months_3.available_from', '2026-12');
    }
}
