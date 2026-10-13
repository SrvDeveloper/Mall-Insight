<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\Mall;
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

class SalesEventControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SalesEvent $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '担当者']);
        $this->actingAs($this->user);
        // 今日は 2026-10-07。販売実績は前日（10-06）まで
        $this->travelTo('2026-10-07 09:00:00');
    }

    /**
     * 対象品番と、そのSKU（Amazon では売っていない）。
     *
     * @param  list<string>  $itemNos
     * @return list<Sku>
     */
    private function targetSkus(array $itemNos): array
    {
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        $skus = [];
        foreach ($itemNos as $rank => $itemNo) {
            $item = Item::factory()->create(['item_no' => $itemNo]);
            $selection->items()->create(['item_no' => $itemNo, 'sales_rank' => $rank + 1, 'is_candidate' => true]);
            $skus[] = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => "{$itemNo}-1", 'child_asin' => null]);
        }

        return $skus;
    }

    /**
     * 楽天市場の販売実績を1日ずつ入れる。
     *
     * @param  callable(string): int  $quantity  日付（Y-m-d） => 販売数
     */
    private function sellDaily(Sku $sku, string $from, string $to, callable $quantity): void
    {
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $count = $quantity($day->toDateString());
            if ($count > 0) {
                $rows[] = ['source' => 'boss', 'source_order_id' => "{$sku->id}-{$day->toDateString()}", 'sales_date' => $day->toDateString(), 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => $count, 'amount' => 1000 * $count];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            SalesLine::insert($chunk);
        }
    }

    private function isOn(string $date, string $from, string $to): bool
    {
        return $date >= $from && $date <= $to;
    }

    /**
     * fl-01 は普段1日2個、楽天スーパーセール（6月・9月の4〜7日）は1日12個（6倍）。si-13 は普段1日1個、セール中は1日2個（2倍）。
     * 楽天スーパーセールのイベントに、6月・9月の期間を登録する。
     *
     * @return list<Sku>
     */
    private function salesWithTwoPastPeriods(): array
    {
        [$popular, $quiet] = $this->targetSkus(['fl-01', 'si-13']);
        $isSale = fn (string $date): bool => $this->isOn($date, '2026-06-04', '2026-06-07') || $this->isOn($date, '2026-09-04', '2026-09-07');
        $this->sellDaily($popular, '2026-04-01', '2026-10-06', fn (string $date): int => $isSale($date) ? 12 : 2);
        $this->sellDaily($quiet, '2026-04-01', '2026-10-06', fn (string $date): int => $isSale($date) ? 2 : 1);
        $this->sale = SalesEvent::factory()->create();
        $this->period('2026-06-04', '2026-06-07');
        $this->period('2026-09-04', '2026-09-07');

        return [$popular, $quiet];
    }

    private function period(string $startsOn, string $endsOn, ?SalesEvent $event = null): SalesEventPeriod
    {
        return SalesEventPeriod::factory()->for($event ?? $this->sale)->period($startsOn, $endsOn)->create();
    }

    public function test_registers_an_event_with_its_first_period_and_adds_more_periods(): void
    {
        $id = $this->postJson('/api/v1/sales-events', ['name' => ' 楽天スーパーセール ', 'malls' => ['yahoo', 'rakuten'], 'note' => '20時から', 'period' => ['starts_on' => '2026-12-04', 'ends_on' => '2026-12-11', 'note' => 'ポイント10倍']])
            ->assertCreated()
            ->assertJsonPath('data.name', '楽天スーパーセール')
            // モールは店舗売上と同じ順（楽天市場が先）。楽天市場・Yahoo!ショッピングはどちらも BOSS
            ->assertJsonPath('data.malls', [['value' => 'rakuten', 'label' => '楽天市場'], ['value' => 'yahoo', 'label' => 'Yahoo!ショッピング']])
            ->assertJsonPath('data.channels', ['boss'])
            ->assertJsonPath('data.created_by', '担当者')
            ->assertJsonPath('data.periods.0.days', 8)
            ->assertJsonPath('data.periods.0.note', 'ポイント10倍')
            ->assertJsonPath('data.periods.0.status', 'upcoming')
            ->assertJsonPath('data.next_period.starts_on', '2026-12-04')
            ->json('data.id');

        // 同じイベントに、過去の期間を足す
        $this->postJson("/api/v1/sales-events/{$id}/periods", ['starts_on' => '2026-09-04', 'ends_on' => '2026-09-11'])->assertCreated()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.event_id', $id);
        // 期間無しでも登録できる
        $other = $this->postJson('/api/v1/sales-events', ['name' => 'プライム感謝祭', 'malls' => ['amazon']])->assertCreated()->assertJsonPath('data.periods', [])->json('data.id');
        $this->postJson("/api/v1/sales-events/{$other}/periods", ['starts_on' => '2026-10-06', 'ends_on' => '2026-10-08'])->assertCreated()->assertJsonPath('data.status', 'ongoing');

        // 次の期間の早い順。期間ごとに、これからは候補、終わったものは測った倍率
        $this->getJson('/api/v1/sales-events')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['プライム感謝祭', '楽天スーパーセール'])
            ->assertJsonPath('data.1.periods.*.starts_on', ['2026-09-04', '2026-12-04'])
            ->assertJsonPath('data.1.periods.0.multipliers.0.kind', 'measured')
            ->assertJsonPath('data.1.periods.0.status_label', '終了')
            ->assertJsonPath('data.1.periods.1.multipliers.0.kind', 'candidate')
            ->assertJsonPath('data.1.upcoming_count', 1)
            ->assertJsonPath('data.1.ended_count', 1)
            ->assertJsonPath('data.1.last_period.starts_on', '2026-09-04')
            ->assertJsonPath('meta.max_days', 31);
    }

    public function test_rejects_a_duplicate_name_and_periods_longer_than_31_days_or_overlapping_in_the_same_event(): void
    {
        $this->sale = SalesEvent::factory()->create();
        $first = $this->period('2026-12-04', '2026-12-11');

        // 名前は重ねない（全角・半角、空白の違いは同じとみなす）
        $this->postJson('/api/v1/sales-events', ['name' => '楽天スーパーセール ', 'malls' => ['rakuten']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', '同じ名前のイベント（楽天スーパーセール）が登録されています。期間はそのイベントに追加してください。');
        $this->postJson('/api/v1/sales-events', ['name' => 'セール', 'malls' => []])->assertUnprocessable()->assertJsonValidationErrors('malls');
        $this->postJson('/api/v1/sales-events', ['name' => 'セール', 'malls' => ['qoo10']])->assertUnprocessable()->assertJsonValidationErrors('malls.0');
        $this->postJson('/api/v1/sales-events', ['name' => 'セール', 'malls' => ['rakuten'], 'period' => ['starts_on' => '2026-12-01']])->assertUnprocessable()->assertJsonValidationErrors('period.ends_on');

        $this->postJson("/api/v1/sales-events/{$this->sale->id}/periods", ['starts_on' => '2026-12-10', 'ends_on' => '2026-12-04'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson("/api/v1/sales-events/{$this->sale->id}/periods", ['starts_on' => '2027-03-01', 'ends_on' => '2027-04-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.ends_on.0', '期間は31日までにしてください。それより長い売れ方の変化は、季節係数で扱います。');
        $this->postJson("/api/v1/sales-events/{$this->sale->id}/periods", ['starts_on' => '2026-12-11', 'ends_on' => '2026-12-12'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_on.0', 'このイベントの別の期間（2026/12/4〜2026/12/11）と重なっています。');
        // 期間を変えるときは、自分の期間とは比べない
        $this->putJson("/api/v1/sales-event-periods/{$first->id}", ['starts_on' => '2026-12-05', 'ends_on' => '2026-12-12', 'note' => '1日ずれた'])->assertOk()->assertJsonPath('data.note', '1日ずれた');
        // 別のイベントなら重なってよい
        $other = SalesEvent::factory()->create(['name' => '超PayPay祭', 'malls' => [Mall::Yahoo]]);
        $this->postJson("/api/v1/sales-events/{$other->id}/periods", ['starts_on' => '2026-12-05', 'ends_on' => '2026-12-06'])->assertCreated();
    }

    public function test_leaves_period_days_out_of_the_base_average_and_multiplies_upcoming_period_days_by_the_candidate(): void
    {
        $this->salesWithTwoPastPeriods();
        $upcoming = $this->period('2026-12-04', '2026-12-07');

        $response = $this->getJson('/api/v1/demand-forecasts')->assertOk();

        // イベントの日を除くと、平均日販はセールの無い日の 2個・1個。直近30日（09-07〜10-06）は 09-07 がイベントの日
        $popular = $response->json('data.0.skus.0.channels.0');
        $this->assertEqualsWithDelta(2.0, $popular['base_average'], 0.0001);
        $this->assertSame(['sales_days' => 29, 'stockout_days' => 0, 'event_days' => 1, 'sales_quantity' => 58], array_intersect_key($popular['windows'][0], array_flip(['sales_days', 'stockout_days', 'event_days', 'sales_quantity'])));
        // 12月：2個×31日に、2個×（6倍－1）×4日を足す。fl-01 は過去2回の期間で普段なら16個のところ96個売れた（6倍）
        $december = collect($popular['monthly'])->firstWhere('month', '2026-12');
        $this->assertEqualsWithDelta(62 + 40, $december['quantity'], 0.01);
        $this->assertSame([['period_id' => $upcoming->id, 'event_id' => $this->sale->id, 'name' => '楽天スーパーセール', 'channel' => 'boss', 'days' => 4, 'multiplier' => 6, 'is_candidate' => true, 'quantity' => 40]], $december['events']);
        // si-13 は普段なら売れた数が8個（10個未満）のため、全体の倍率（112÷24＝4.67倍）を使う
        $quietDecember = collect($response->json('data.1.skus.0.monthly'))->firstWhere('month', '2026-12');
        $this->assertEqualsWithDelta(31 + 1 * 3.67 * 4, $quietDecember['quantity'], 0.01);
        $this->assertEqualsWithDelta(4.67, $quietDecember['events'][0]['multiplier'], 0.0001);
        $this->assertSame(0, collect($popular['monthly'])->firstWhere('month', '2026-11')['event_quantity']);
        $response->assertJsonPath('meta.events.2.id', $upcoming->id)->assertJsonPath('meta.events.2.event_id', $this->sale->id)->assertJsonPath('meta.events.2.status', 'upcoming');

        // 期間の画面：候補の根拠（同じイベントの過去の期間）と、品番ごとの候補・普段の予測・増える数
        $detail = $this->getJson("/api/v1/sales-event-periods/{$upcoming->id}")->assertOk()->assertJsonPath('data.remaining_days', 4)->assertJsonPath('data.event.name', '楽天スーパーセール');
        $boss = $detail->json('data.channels.0');
        $this->assertSame(4.67, $boss['overall']);
        $this->assertSame(['2026-09-04', '2026-06-04'], array_column($boss['basis'], 'starts_on'));
        $items = collect($boss['items'])->keyBy('item_no');
        $this->assertSame(['candidate' => 6, 'uses_overall' => false, 'past_expected' => 16, 'past_actual' => 96, 'override' => null, 'multiplier' => 6, 'usual' => 8, 'extra' => 40], array_intersect_key($items['fl-01'], array_flip(['candidate', 'uses_overall', 'past_expected', 'past_actual', 'override', 'multiplier', 'usual', 'extra'])));
        $this->assertTrue($items['si-13']['uses_overall']);

        // 別のイベントの期間は、候補の根拠にしない
        $other = SalesEvent::factory()->create(['name' => 'お買い物マラソン']);
        $marathon = $this->period('2026-12-20', '2026-12-22', $other);
        $this->getJson("/api/v1/sales-event-periods/{$marathon->id}")->assertJsonPath('data.channels.0.overall', null)->assertJsonPath('data.channels.0.items.0.multiplier', null);
    }

    public function test_uses_the_multiplier_the_staff_entered_for_the_period_instead_of_the_candidate(): void
    {
        $this->salesWithTwoPastPeriods();
        $december = $this->period('2026-12-04', '2026-12-07');
        $march = $this->period('2027-03-04', '2027-03-07');

        $this->putJson("/api/v1/sales-event-periods/{$december->id}/multipliers", ['multipliers' => [['item_no' => 'fl-01', 'channel' => 'boss', 'multiplier' => 3.5]]])
            ->assertOk()
            ->assertJsonPath('data.channels.0.items.0.override', 3.5)
            ->assertJsonPath('data.channels.0.items.0.candidate', 6)
            // 2個×（3.5倍－1）×4日
            ->assertJsonPath('data.channels.0.items.0.extra', 20)
            ->assertJsonPath('data.updated_by', '担当者');

        // 入れた倍率はその期間だけに使い、ほかの期間は候補のまま
        $months = collect($this->getJson('/api/v1/demand-forecasts')->json('data.0.skus.0.monthly'))->keyBy('month');
        $this->assertEqualsWithDelta(62 + 20, $months['2026-12']['quantity'], 0.01);
        $this->assertFalse($months['2026-12']['events'][0]['is_candidate']);
        $this->assertSame(6, $months['2027-03']['events'][0]['multiplier']);
        $this->getJson('/api/v1/sales-events')->assertJsonPath('data.0.periods.2.multipliers.0.override_count', 1)->assertJsonPath('data.0.periods.3.multipliers.0.override_count', 0);

        $this->putJson("/api/v1/sales-event-periods/{$december->id}/multipliers", ['multipliers' => [['item_no' => 'fl-01', 'channel' => 'amazon', 'multiplier' => 2]]])->assertUnprocessable()->assertJsonValidationErrors('multipliers.0.channel');
        $this->putJson("/api/v1/sales-event-periods/{$december->id}/multipliers", ['multipliers' => [['item_no' => 'fl-01', 'channel' => 'boss', 'multiplier' => 2.125]]])->assertUnprocessable()->assertJsonValidationErrors('multipliers.0.multiplier');

        // イベントのモールから楽天市場を外すと、すべての期間で BOSS に入れた倍率を消す
        $this->putJson("/api/v1/sales-events/{$this->sale->id}", ['name' => '楽天スーパーセール', 'malls' => ['amazon']])->assertOk()->assertJsonPath('data.channels', ['amazon']);
        $this->assertSame(0, $december->multipliers()->count());
        $this->assertNotNull($march->fresh());
    }

    public function test_shows_how_many_times_more_an_ended_period_sold_and_does_not_take_multipliers_for_it(): void
    {
        $this->salesWithTwoPastPeriods();
        $ended = SalesEventPeriod::query()->whereDate('starts_on', '2026-09-04')->firstOrFail();

        $this->getJson("/api/v1/sales-event-periods/{$ended->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.channels.0.kind', 'measured')
            // 普段なら 2×4＋1×4＝12個のところ、48＋8＝56個売れた
            ->assertJsonPath('data.channels.0.expected', 12)
            ->assertJsonPath('data.channels.0.actual', 56)
            ->assertJsonPath('data.channels.0.overall', 4.67)
            ->assertJsonPath('data.channels.0.items.0.ratio', 6)
            ->assertJsonPath('data.channels.0.items.1.ratio', 2);

        $this->putJson("/api/v1/sales-event-periods/{$ended->id}/multipliers", ['multipliers' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.multipliers.0', '終了した期間の倍率は、需要予測に使わないため変えられません。');

        // 販売実績を取り込んでいないチャネルでは測らない
        $amazon = $this->period('2026-07-07', '2026-07-10', SalesEvent::factory()->create(['name' => 'プライムデー', 'malls' => [Mall::Amazon]]));
        $this->getJson("/api/v1/sales-event-periods/{$amazon->id}")->assertJsonPath('data.channels.0.overall', null)->assertJsonPath('data.channels.0.note', 'Amazonの販売実績がまだ取り込まれていないため、測れません。');

        // 期間を消す。イベントを消すと期間ごと消える
        $this->deleteJson("/api/v1/sales-event-periods/{$ended->id}")->assertNoContent();
        $this->assertNull($ended->fresh());
        $this->deleteJson("/api/v1/sales-events/{$this->sale->id}")->assertNoContent();
        $this->assertSame(1, SalesEventPeriod::query()->count());
    }

    public function test_counts_the_increase_of_an_ongoing_period_in_full_for_the_rest_of_this_month_in_the_inventory_trend(): void
    {
        [$popular] = $this->salesWithTwoPastPeriods();
        // 今日（10-07）から 10-10 まで。今月の残りは25日
        $this->period('2026-10-07', '2026-10-10');
        Inventory::create(['stock_date' => '2026-10-07', 'sku_id' => $popular->id, 'warehouse' => 'boss_own', 'quantity' => 1000]);

        // 2個×25日 ＋ 2個×（6倍－1）×4日
        $this->getJson('/api/v1/inventory-trends')->assertOk()->assertJsonPath('data.0.skus.0.months.0.demand', 50 + 40);
    }
}
