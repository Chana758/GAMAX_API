<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\Feature\Concerns\InteractsWithOrders;
use Tests\TestCase;

/** KHQR: order waits for payment -> customer taps "I've paid" -> staff mark it paid (or it expires). */
class KhqrOrderTest extends TestCase
{
    use RefreshDatabase, InteractsWithCatalog, InteractsWithOrders;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config([
            'shop.free_shipping_over'        => 50,
            'shop.zones.capital'             => ['shipping_fee' => 2, 'cod_fee' => 1, 'delivery_days' => '1-3'],
            'shop.payment_methods'           => ['khqr', 'cod'],
            'shop.free_shipping_for_prepaid' => false,
            'shop.khqr.currency'             => 'KHR',
            'shop.khqr.khr_per_usd'          => 4100,
            'shop.khqr.pay_within_hours'     => 24,
        ]);

        $this->variantId = $this->firstVariantId($this->makeProduct([], [['price' => 19.99, 'stock_quantity' => 5]]));
    }

    private function placeKhqr(?User $user = null): string
    {
        $user ??= $this->actingAsRole('customer');
        $address = $this->makeAddress($user);

        return $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$this->variantId, 1]], ['payment_method' => 'khqr']))
            ->assertCreated()
            ->json('data.number');
    }

    public function test_settings_tell_the_storefront_about_the_qr(): void
    {
        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.payment_methods', ['khqr', 'cod'])
            ->assertJsonPath('data.khqr.currency', 'KHR')
            ->assertJsonPath('data.khqr.pay_within_hours', 24);
    }

    public function test_khqr_order_waits_for_payment_with_the_amount_in_riel(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $body = $this->checkout($address->id, [[$this->variantId, 1]], ['payment_method' => 'khqr']);

        // $19.99 + $2 delivery = $21.99, no COD fee; 21.99 x 4100 = 90,159 riel -> 90,200
        $this->postJson('/api/v1/orders/quote', $body)
            ->assertOk()
            ->assertJsonPath('data.cod_fee', 0)
            ->assertJsonPath('data.total', 21.99)
            ->assertJsonPath('data.payment_amount', 90200)
            ->assertJsonPath('data.payment_currency', 'KHR')
            ->assertJsonPath('data.payment_exchange_rate', 4100);

        $response = $this->postJson('/api/v1/orders', $body)
            ->assertCreated()
            ->assertJsonPath('data.status', Order::PENDING)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment_amount', 90200)
            ->assertJsonPath('data.payment_exchange_rate', 4100)
            ->assertJsonPath('data.awaits_payment', true)
            ->assertJsonPath('data.can_cancel', true);

        $due = Order::where('number', $response->json('data.number'))->value('payment_due_at');
        $this->assertTrue(now()->addHours(24)->diffInMinutes($due, true) < 2);
    }

    public function test_usd_qr_and_free_shipping_option(): void
    {
        config(['shop.khqr.currency' => 'USD', 'shop.free_shipping_for_prepaid' => true]);
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);

        $this->postJson('/api/v1/orders/quote', $this->checkout($address->id, [[$this->variantId, 1]], ['payment_method' => 'khqr']))
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.payment_amount', 19.99)
            ->assertJsonPath('data.payment_currency', 'USD');

        // COD still pays delivery + COD fee
        $this->postJson('/api/v1/orders/quote', $this->checkout($address->id, [[$this->variantId, 1]]))
            ->assertJsonPath('data.total', 22.99)
            ->assertJsonPath('data.payment_amount', null);
    }

    public function test_customer_reports_payment_then_cannot_cancel_alone(): void
    {
        $number = $this->placeKhqr();

        $this->postJson("/api/v1/orders/{$number}/payment", ['reference' => '  FT123456  '])
            ->assertOk()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_SUBMITTED)
            ->assertJsonPath('data.payment_reference', 'FT123456')
            ->assertJsonPath('data.awaits_payment', false)
            ->assertJsonPath('data.can_cancel', false);

        // Money was sent: the shop handles cancellation and refund
        $this->postJson("/api/v1/orders/{$number}/cancel")->assertUnprocessable();
    }

    public function test_only_the_owner_of_a_waiting_khqr_order_can_report_payment(): void
    {
        $number = $this->placeKhqr();

        // A COD order never waits for an online payment
        $customer = Order::where('number', $number)->first()->user;
        $address = $customer->addresses()->first();
        $cod = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$this->variantId, 1]]))->json('data.number');
        $this->postJson("/api/v1/orders/{$cod}/payment")->assertUnprocessable();

        $this->actingAsRole('customer');
        $this->postJson("/api/v1/orders/{$number}/payment")->assertNotFound();
    }

    public function test_staff_marking_paid_confirms_the_order(): void
    {
        $number = $this->placeKhqr();
        $this->postJson("/api/v1/orders/{$number}/payment")->assertOk();

        $this->actingAsRole('staff');
        // 90,200 riel at 4,100 = $22.00; within one 100-riel step of $21.99, so it counts as exact
        $this->postJson("/api/v1/admin/orders/{$number}/payments", [
            'method' => 'khqr', 'currency' => 'KHR', 'amount_received' => 90200, 'exchange_rate' => 4100,
            'payer_account' => 'dara@aba', 'reference' => 'a1b2c3d4',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Order::CONFIRMED)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('data.payments.0.amount', 21.99)
            ->assertJsonPath('data.payments.0.amount_received', 90200)
            ->assertJsonPath('data.payments.0.exchange_rate', 4100)
            ->assertJsonPath('data.payments.0.provider', 'bakong');

        $order = Order::where('number', $number)->first();
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->confirmed_at);
    }

    public function test_staff_confirming_a_khqr_order_marks_it_paid(): void
    {
        $number = $this->placeKhqr();

        $this->actingAsRole('staff');
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            // Recorded in riel at the rate the customer was given
            ->assertJsonPath('data.payments.0.currency', 'KHR')
            ->assertJsonPath('data.payments.0.amount_received', 90200)
            ->assertJsonPath('data.payments.0.exchange_rate', 4100)
            ->assertJsonPath('data.payments.0.amount', 21.99);
    }

    public function test_unpaid_orders_expire_and_the_stock_comes_back(): void
    {
        $customer = $this->actingAsRole('customer');
        $unpaid = $this->placeKhqr($customer);
        $reported = $this->placeKhqr($customer);
        $this->postJson("/api/v1/orders/{$reported}/payment")->assertOk();
        $this->assertSame(3, ProductVariant::find($this->variantId)->stock_quantity);

        // Not due yet: nothing happens
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();
        $this->assertSame(Order::PENDING, Order::where('number', $unpaid)->value('status'));

        $this->travel(25)->hours();
        $this->artisan('orders:cancel-unpaid')->expectsOutput('Cancelled 1 unpaid order(s).')->assertSuccessful();

        $order = Order::where('number', $unpaid)->first();
        $this->assertSame(Order::CANCELLED, $order->status);
        $this->assertSame('system', $order->cancelled_by);
        // The customer said they paid: staff decide
        $this->assertSame(Order::PENDING, Order::where('number', $reported)->value('status'));
        $this->assertSame(4, ProductVariant::find($this->variantId)->stock_quantity);
    }
}
