<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Models\ForecastAdjustment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastAdjustmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '担当者']);
        $this->actingAs($this->user);
        $this->travelTo('2026-10-14 09:00:00');
        Item::factory()->create(['item_no' => 'fl-01']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return ['item_no' => 'fl-01', 'channel' => 'amazon', 'starts_on' => '2026-11', 'ends_on' => '2027-01', 'rate_percent' => 120, 'reason' => 'Amazon の広告を強める', ...$overrides];
    }

    public function test_registers_an_adjustment_of_an_item_and_channel_with_the_user(): void
    {
        $this->postJson('/api/v1/forecast-adjustments', $this->input())
            ->assertCreated()
            ->assertJsonPath('data.channel_label', 'Amazon')
            ->assertJsonPath('data.starts_on', '2026-11')
            ->assertJsonPath('data.ends_on', '2027-01')
            ->assertJsonPath('data.rate_percent', 120)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.created_by', '担当者');

        // 終える月を空にすると、取り消すまで続ける
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['channel' => 'boss', 'starts_on' => '2026-10', 'ends_on' => null, 'rate_percent' => 0]))
            ->assertCreated()
            ->assertJsonPath('data.ends_on', null)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_rejects_past_months_reversed_months_rates_out_of_range_and_a_missing_reason(): void
    {
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['starts_on' => '2026-09']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_on.0', '始める月は今月より後にしてください。過去の月の予測は補正しません。');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['ends_on' => '2026-10']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['rate_percent' => 501]))->assertUnprocessable()->assertJsonValidationErrors('rate_percent');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['rate_percent' => 100]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.rate_percent.0', '100%は補正しないのと同じです。補正をやめるときは取り消してください。');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['reason' => '']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['item_no' => 'nothing']))->assertUnprocessable()->assertJsonValidationErrors('item_no');
        $this->assertSame(0, ForecastAdjustment::count());
    }

    public function test_does_not_overlap_the_months_of_the_same_item_and_channel_but_replaces_one_when_changing_it(): void
    {
        $current = ForecastAdjustment::factory()->adjusting('fl-01', Channel::Amazon, '2026-12', null, 150)->create();
        // 取り消した補正・ほかのチャネルの補正とは重ねてよい
        ForecastAdjustment::factory()->adjusting('fl-01', Channel::Amazon, '2026-10', null, 150)->create(['cancelled_at' => now()]);
        ForecastAdjustment::factory()->adjusting('fl-01', Channel::Boss, '2026-10', null, 150)->create();

        $this->postJson('/api/v1/forecast-adjustments', $this->input())
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_on.0', 'このチャネルの別の補正（2026/12〜、150%）と月が重なっています。その補正を変えるか、取り消してください。');
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['ends_on' => '2026-11']))->assertCreated();

        // 変えるときは、変える補正を取り消して新しい補正を登録する
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['starts_on' => '2026-12', 'ends_on' => null, 'rate_percent' => 130, 'replaces' => $current->id]))->assertCreated();
        $this->assertNotNull($current->fresh()->cancelled_at);
        $this->assertSame($this->user->id, $current->fresh()->cancelled_by_user_id);
        // 取り消した補正は変えられない。品番・チャネルの違う補正にも置き換えられない
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['starts_on' => '2027-06', 'ends_on' => null, 'replaces' => $current->id]))->assertUnprocessable()->assertJsonValidationErrors('replaces');
        $boss = ForecastAdjustment::query()->where('channel', 'boss')->first();
        $this->postJson('/api/v1/forecast-adjustments', $this->input(['starts_on' => '2027-06', 'ends_on' => null, 'replaces' => $boss->id]))->assertUnprocessable()->assertJsonValidationErrors('replaces');
    }

    public function test_cancels_an_adjustment_and_keeps_it_in_the_history(): void
    {
        $adjustment = ForecastAdjustment::factory()->adjusting('fl-01', Channel::Amazon, '2026-10', null, 150)->create();
        ForecastAdjustment::factory()->adjusting('fl-01', Channel::Boss, '2026-07', '2026-09', 80)->create();
        ForecastAdjustment::factory()->adjusting('si-13', Channel::Boss, '2026-10', null, 80)->create();

        $this->postJson("/api/v1/forecast-adjustments/{$adjustment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', '担当者');
        $this->postJson("/api/v1/forecast-adjustments/{$adjustment->id}/cancel")->assertUnprocessable();

        // 品番の補正を、取り消した・終えた補正も含めて新しい順に返す
        $this->getJson('/api/v1/forecast-adjustments?item_no=fl-01')
            ->assertOk()
            ->assertJsonPath('data.*.status', ['ended', 'cancelled'])
            ->assertJsonPath('meta.max_percent', 500);
    }
}
