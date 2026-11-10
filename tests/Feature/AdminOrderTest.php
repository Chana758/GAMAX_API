<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\Feature\Concerns\InteractsWithOrders;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase, InteractsWithCatalog, InteractsWithOrders;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        config(['shop.payment_methods' => ['cod']]);
        $this->variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'stock_quantity' => 3]]));
    }

    private function placeOrder(array $address = []): string
    {
        $customer = $this->actingAsRole('customer');
        $addr = $this->makeAddress($customer, $address);

        return $this->postJson('/api/v1/orders', $this->checkout($addr->id, [[$this->variantId, 1]]))
            ->assertCreated()
            ->json('data.number');
    }

    public function test_customers_cannot_open_admin_orders(): void
    {
        $number = $this->placeOrder();

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'confirmed'])->assertForbidden();
    }

    public function test_staff_list_filter_and_search(): void
    {
        $first = $this->placeOrder(['full_name' => 'Chan Vanna', 'phone' => '098765432']);
        $this->placeOrder();
        Order::where('number', $first)->update(['status' => Order::CONFIRMED]);

        $this->actingAsRole('staff');

        $this->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.status_counts.confirmed', 1)
            ->assertJsonPath('meta.status_counts.pending_confirmation', 1);

        $this->getJson('/api/v1/admin/orders?status=confirmed')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $first);

        $this->getJson('/api/v1/admin/orders?search=098 765')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.name', fn ($n) => $n !== null);

        $this->getJson('/api/v1/admin/orders?search=vanna')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/orders?search=' . strtolower($first))->assertJsonCount(1, 'data');
    }

    public function test_staff_move_an_order_to_delivered(): void
    {
        $number = $this->placeOrder();
        $this->actingAsRole('staff');

        $this->getJson("/api/v1/admin/orders/{$number}")
            ->assertOk()
            ->assertJsonPath('data.next_statuses', [Order::CONFIRMED, Order::CANCELLED]);

        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'confirmed', 'admin_note' => 'Called, OK'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.admin_note', 'Called, OK');

        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'shipped', 'tracking_number' => 'VET-123'])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'VET-123');

        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid'); // COD: courier collected the money

        $order = Order::where('number', $number)->first();
        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->delivered_at);

        // Delivered is final
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_staff_cancel_puts_the_stock_back(): void
    {
        $number = $this->placeOrder();
        $this->assertSame(2, ProductVariant::find($this->variantId)->stock_quantity);

        $this->actingAsRole('staff');
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'cancelled', 'cancel_reason' => 'Customer did not answer'])
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', 'staff')
            ->assertJsonPath('data.cancel_reason', 'Customer did not answer');

        $this->assertSame(3, ProductVariant::find($this->variantId)->stock_quantity);
    }

    public function test_customers_do_not_see_the_staff_note(): void
    {
        $number = $this->placeOrder();
        Order::where('number', $number)->update(['admin_note' => 'VIP, give a free mouse pad']);

        $customer = Order::where('number', $number)->first()->user;
        $this->actingAs($customer);

        $this->getJson("/api/v1/orders/{$number}")
            ->assertOk()
            ->assertJsonMissingPath('data.admin_note');
    }
}
