<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_register_creates_a_customer_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Sok Dara',
            'email'                 => 'dara@example.com',
            'phone'                 => '012345678',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'dara@example.com')
            ->assertJsonPath('data.user.roles', ['customer']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertTrue(User::where('email', 'dara@example.com')->first()->hasRole('customer'));
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'X',
            'email'                 => 'taken@example.com',
            'password'              => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_with_correct_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => Hash::make('Secret123')]);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'Wrong123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user' => ['id', 'email'], 'token']]);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email'     => 'off@example.com',
            'password'  => Hash::make('Secret123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', ['email' => 'off@example.com', 'password' => 'Secret123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'b@example.com', 'password' => Hash::make('Secret123')]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'Secret123'])
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'b@example.com');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards(); // the test client caches the logged-in user between requests
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logged_in_user_cannot_use_guest_only_routes(): void
    {
        $this->actingAsRole('customer');

        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'Secret123'])
            ->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'Wrong123']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'Wrong123'])
            ->assertStatus(429);
    }
}
