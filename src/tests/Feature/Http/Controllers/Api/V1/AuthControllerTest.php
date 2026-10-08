<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '在庫 担当', 'email' => 'zaiko@example.com', 'password' => 'correct-horse']);
    }

    public function test_a_registered_user_logs_in_with_email_and_password(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'zaiko@example.com', 'password' => 'correct-horse'])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->user->id, 'name' => '在庫 担当', 'email' => 'zaiko@example.com']]);

        $this->assertAuthenticatedAs($this->user);
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.name', '在庫 担当');
    }

    public function test_rejects_a_wrong_password_or_an_unregistered_email_without_telling_which(): void
    {
        foreach ([['zaiko@example.com', 'wrong-password'], ['nobody@example.com', 'correct-horse']] as [$email, $password]) {
            $this->postJson('/api/v1/login', ['email' => $email, 'password' => $password])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email', ['メールアドレスまたはパスワードが正しくありません。']);
        }

        $this->assertGuest();
    }

    public function test_stops_accepting_logins_for_a_minute_after_five_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'zaiko@example.com', 'password' => 'wrong-password'])->assertUnprocessable();
        }

        // 6回目は、正しいパスワードでも受け付けない
        $this->postJson('/api/v1/login', ['email' => 'zaiko@example.com', 'password' => 'correct-horse'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', fn (string $message) => str_starts_with($message, 'ログインの試行回数が多すぎます。'));
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', ['email' => 'zaiko@example.com', 'password' => 'correct-horse'])->assertOk();
    }

    public function test_logs_out(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_every_api_except_login_requires_a_logged_in_user(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/') && $route->getName() !== 'api.v1.login');
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $method = $route->methods()[0];
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            $this->json($method, $uri)->assertUnauthorized();
        }
    }
}
