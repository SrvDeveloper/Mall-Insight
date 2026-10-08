<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_a_user_who_can_log_in(): void
    {
        $this->artisan('user:create', ['email' => 'zaiko@example.com', 'name' => '在庫 担当'])
            ->expectsQuestion('パスワード（8文字以上）', 'correct-horse')
            ->expectsQuestion('パスワード（確認のためもう一度）', 'correct-horse')
            ->expectsOutput('zaiko@example.com（在庫 担当）を登録しました。')
            ->assertSuccessful();

        $this->postJson('/api/v1/login', ['email' => 'zaiko@example.com', 'password' => 'correct-horse'])->assertOk();
    }

    public function test_does_not_register_a_duplicate_email_a_short_password_or_mismatched_passwords(): void
    {
        User::factory()->create(['email' => 'zaiko@example.com']);

        $this->artisan('user:create', ['email' => 'zaiko@example.com', 'name' => '在庫 担当'])->assertFailed();
        $this->artisan('user:create', ['email' => 'new@example.com', 'name' => '新人'])
            ->expectsQuestion('パスワード（8文字以上）', 'short')
            ->expectsQuestion('パスワード（確認のためもう一度）', 'short')
            ->assertFailed();
        $this->artisan('user:create', ['email' => 'new@example.com', 'name' => '新人'])
            ->expectsQuestion('パスワード（8文字以上）', 'correct-horse')
            ->expectsQuestion('パスワード（確認のためもう一度）', 'correct-house')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_resets_the_password_of_a_registered_user(): void
    {
        $user = User::factory()->create(['email' => 'zaiko@example.com', 'password' => 'old-password']);

        $this->artisan('user:reset-password', ['email' => 'zaiko@example.com'])
            ->expectsQuestion('パスワード（8文字以上）', 'new-password')
            ->expectsQuestion('パスワード（確認のためもう一度）', 'new-password')
            ->assertSuccessful();
        $this->artisan('user:reset-password', ['email' => 'nobody@example.com'])->assertFailed();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }
}
