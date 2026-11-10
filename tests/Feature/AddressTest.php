<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\Feature\Concerns\InteractsWithOrders;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase, InteractsWithCatalog, InteractsWithOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'label'     => 'Home',
            'full_name' => '  Sok   Dara ',
            'phone'     => '012 345-678',
            'email'     => 'dara@example.com',
            'province'  => 'Siem Reap',
            'address'   => '#5, Street 6, Svay Dangkum, Siem Reap city',
        ];
    }

    public function test_guest_cannot_use_addresses(): void
    {
        $this->getJson('/api/v1/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/addresses', $this->form())->assertUnauthorized();
    }

    public function test_customer_saves_an_address_and_the_first_one_is_default(): void
    {
        $user = $this->actingAsRole('customer');

        $this->postJson('/api/v1/addresses', $this->form())
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Sok Dara')
            ->assertJsonPath('data.phone', '012345678')   // spaces and dashes removed
            ->assertJsonPath('data.is_default', true);

        $this->postJson('/api/v1/addresses', $this->form(['address' => 'Office tower, Street 51, Daun Penh']))
            ->assertCreated()
            ->assertJsonPath('data.is_default', false);

        // Another customer's addresses are not listed
        $this->makeAddress(\App\Models\User::factory()->create());

        $this->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_default', true);
        $this->assertSame(2, $user->addresses()->count());
    }

    public function test_bad_phone_province_and_short_address_are_rejected(): void
    {
        $this->actingAsRole('customer');

        $this->postJson('/api/v1/addresses', $this->form([
            'phone'    => '12345',
            'province' => 'Bangkok',
            'address'  => 'PP',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'province', 'address']);
    }

    public function test_the_same_address_twice_is_rejected(): void
    {
        $this->actingAsRole('customer');

        $this->postJson('/api/v1/addresses', $this->form())->assertCreated();
        $this->postJson('/api/v1/addresses', $this->form(['address' => strtoupper($this->form()['address'])]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
    }

    public function test_address_limit(): void
    {
        config(['shop.max_addresses' => 1]);
        $this->actingAsRole('customer');

        $this->postJson('/api/v1/addresses', $this->form())->assertCreated();
        $this->postJson('/api/v1/addresses', $this->form(['address' => 'Another place, Street 2004, Sen Sok']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
    }

    public function test_customer_cannot_touch_someone_elses_address(): void
    {
        $other = $this->makeAddress(\App\Models\User::factory()->create());
        $this->actingAsRole('customer');

        $this->putJson("/api/v1/addresses/{$other->id}", $this->form())->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$other->id}")->assertNotFound();
        $this->assertDatabaseHas('addresses', ['id' => $other->id]);
    }

    public function test_one_default_at_a_time_and_deleting_it_promotes_another(): void
    {
        $user = $this->actingAsRole('customer');
        $first = $this->makeAddress($user);
        $second = $this->makeAddress($user, ['address' => 'Second home, Street 99, Tuol Kork', 'is_default' => false]);

        $this->putJson("/api/v1/addresses/{$second->id}", $this->form(['is_default' => true]))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);
        $this->assertFalse($first->fresh()->is_default);

        $this->deleteJson("/api/v1/addresses/{$second->id}")->assertOk();
        $this->assertTrue($first->fresh()->is_default);
    }
}
