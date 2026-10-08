<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\InventoryTrendSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTrendSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_new_check_month_and_keeps_the_previous_one(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $user = User::factory()->create();
        InventoryTrendSetting::create(['check_month_offset' => 6]);

        $this->actingAs($user)
            ->postJson('/api/v1/inventory-trend-settings', ['check_month_offset' => 3])
            ->assertCreated()
            ->assertExactJson(['data' => ['check_month_offset' => 3, 'changed_at' => '2026-10-08T10:00:00+09:00']]);

        $this->assertSame([6, 3], InventoryTrendSetting::query()->orderBy('id')->pluck('check_month_offset')->all());
        $this->assertSame($user->id, InventoryTrendSetting::current()?->changed_by_user_id);
    }

    public function test_accepts_only_months_within_the_twelve_month_trend(): void
    {
        foreach ([0, 12, 'a', null] as $offset) {
            $this->postJson('/api/v1/inventory-trend-settings', ['check_month_offset' => $offset])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('check_month_offset');
        }
        foreach ([1, 11] as $offset) {
            $this->postJson('/api/v1/inventory-trend-settings', ['check_month_offset' => $offset])->assertCreated();
        }

        $this->assertSame(11, InventoryTrendSetting::current()?->check_month_offset);
    }
}
