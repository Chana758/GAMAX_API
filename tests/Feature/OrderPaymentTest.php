<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\Feature\Concerns\InteractsWithOrders;
use Tests\TestCase;

/** Staff record money received / given back; amount_paid and payment_status follow the rows. */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase, InteractsWithCatalog, InteractsWithOrders;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        config([
            'shop.payment_methods'           => ['khqr', 'cod'],
            'shop.free_shipping_over'        => 50,
            'shop.free_shipping_for_prepaid' => false,
        ]);
        // $100 item: free delivery, so the total is $100 (+ COD fee in Phnom Penh = 0)
        config(['shop.zones.capital' => ['shipping_fee' => 2, 'cod_fee' => 0, 'delivery_days' => '1-3']]);
        $this->variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'stock_quantity' => 5]]));
    }

    private function place(string $method = 'khqr'): string
    {
        $customer = $this->actingAsRole('customer');
        $address = $this->makeAddress($customer);

        return $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$this->variantId, 1]], ['payment_method' => $method]))
            ->assertCreated()
            ->json('data.number');
    }

    private function pay(string $number, float $amount, array $extra = [])
    {
        return $this->postJson("/api/v1/admin/orders/{$number}/payments", $extra + ['method' => 'cash', 'amount' => $amount]);
    }

    public function test_partial_then_full_payment_confirms_a_waiting_order(): void
    {
        $number = $this->place();
        $staff = $this->actingAsRole('staff');

        $this->pay($number, 30, ['note' => 'Deposit at the counter'])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PARTIAL)
            ->assertJsonPath('data.amount_paid', 30)
            ->assertJsonPath('data.balance_due', 70)
            ->assertJsonPath('data.status', Order::PENDING);

        $this->pay($number, 70, ['method' => 'khqr', 'payer_account' => 'dara@aclb', 'reference' => 'FT001'])
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('data.status', Order::CONFIRMED)
            ->assertJsonPath('data.payments.1.payer_account', 'dara@aclb')
            ->assertJsonPath('data.payments.0.recorded_by.id', $staff->id);

        $this->assertNotNull(Order::where('number', $number)->value('paid_at'));
    }

    public function test_two_people_paying_shows_overpaid_and_admin_refunds_the_extra(): void
    {
        $number = $this->place();

        $this->actingAsRole('staff');
        $this->pay($number, 100, ['method' => 'khqr', 'payer_account' => 'mother@aba'])->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);
        $this->pay($number, 100, ['method' => 'khqr', 'payer_account' => 'brother@wing'])
            ->assertJsonPath('data.payment_status', Order::PAYMENT_OVERPAID)
            ->assertJsonPath('data.balance_due', -100);

        // Staff can't give money back
        $this->postJson("/api/v1/admin/orders/{$number}/refunds", ['method' => 'khqr', 'amount' => 100, 'note' => 'Paid twice'])
            ->assertForbidden();

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/orders/{$number}/refunds", ['method' => 'khqr', 'amount' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/admin/orders/{$number}/refunds", [
            'method' => 'khqr', 'amount' => 100, 'payer_account' => 'brother@wing', 'note' => 'Paid twice from the family group',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('data.amount_paid', 100)
            ->assertJsonPath('data.payments.2.type', 'refund');

        // Can't refund more than was paid
        $this->postJson("/api/v1/admin/orders/{$number}/refunds", ['method' => 'cash', 'amount' => 100.01, 'note' => 'Too much'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_full_refund_after_cancelling_a_paid_order(): void
    {
        $number = $this->place();
        $this->actingAsRole('admin');
        $this->pay($number, 100, ['method' => 'khqr']);
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => 'cancelled', 'cancel_reason' => 'Out of stock at supplier'])->assertOk();

        $this->getJson('/api/v1/admin/orders/summary')->assertJsonPath('data.to_refund', 1);
        $this->getJson('/api/v1/admin/orders?needs_refund=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $number);

        $this->postJson("/api/v1/admin/orders/{$number}/refunds", ['method' => 'khqr', 'amount' => 100, 'note' => 'Order cancelled'])
            ->assertJsonPath('data.payment_status', Order::PAYMENT_REFUNDED)
            ->assertJsonPath('data.amount_paid', 0);

        $this->getJson('/api/v1/admin/orders/summary')->assertJsonPath('data.to_refund', 0);
    }

    public function test_cod_delivery_and_staff_confirmation_create_payment_rows(): void
    {
        $cod = $this->place('cod');
        $khqr = $this->place('khqr');

        $this->actingAsRole('staff');
        foreach (['confirmed', 'shipped', 'delivered'] as $status) {
            $this->patchJson("/api/v1/admin/orders/{$cod}", ['status' => $status])->assertOk();
        }
        $this->getJson("/api/v1/admin/orders/{$cod}")
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.method', 'cod')
            ->assertJsonPath('data.payments.0.note', 'Collected on delivery');

        $this->patchJson("/api/v1/admin/orders/{$khqr}", ['status' => 'confirmed'])
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('data.payments.0.method', 'khqr');

        // payment_status can't be set by hand any more
        $this->patchJson("/api/v1/admin/orders/{$cod}", ['payment_status' => 'unpaid'])->assertOk();
        $this->assertSame(Order::PAYMENT_PAID, Order::where('number', $cod)->value('payment_status'));
    }

    public function test_customer_cannot_cancel_after_a_deposit_and_cannot_record_money(): void
    {
        $number = $this->place();
        $customer = Order::where('number', $number)->first()->user;

        $this->actingAsRole('staff');
        $this->pay($number, 20);

        $this->actingAs($customer);
        $this->postJson("/api/v1/orders/{$number}/cancel")->assertUnprocessable();
        $this->getJson("/api/v1/orders/{$number}")->assertJsonPath('data.amount_paid', 20)->assertJsonMissingPath('data.payments');
        $this->pay($number, 80)->assertForbidden();
    }

    public function test_summary_and_filters(): void
    {
        $cod = $this->place('cod');
        $khqr = $this->place('khqr');
        $this->postJson("/api/v1/orders/{$khqr}/payment")->assertOk(); // customer: "I've paid"

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/admin/orders/summary')
            ->assertOk()
            ->assertJsonPath('data.to_call', 1)
            ->assertJsonPath('data.payment_to_check', 1)
            ->assertJsonPath('data.needs_action', 2);

        $this->getJson('/api/v1/admin/orders?payment_status=submitted')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $khqr);
        $this->getJson('/api/v1/admin/orders?payment_method=cod')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $cod);
        $this->getJson('/api/v1/admin/orders?channel=web&from=' . now()->toDateString())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/orders?channel=store')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders?status=pending,pending_confirmation')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/orders?status=pending,nope')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/admin/orders?from=' . now()->addDay()->toDateString())->assertJsonCount(0, 'data');
    }

    public function test_stats_for_the_owner(): void
    {
        // Last month, inside the "same days" window: counts as prev
        $this->travelTo(now('Asia/Phnom_Penh')->startOfMonth()->subMonthNoOverflow()->addSecond());
        $old = $this->place();
        $this->actingAsRole('staff');
        $this->pay($old, 100);
        $this->travelBack();

        $paid = $this->place();
        $cod = $this->place('cod');
        $part = $this->place();
        $cancelled = $this->place('cod');
        $this->postJson("/api/v1/orders/{$cancelled}/cancel")->assertOk();

        $this->actingAsRole('staff');
        $this->pay($paid, 100);
        $this->pay($part, 30);

        $data = $this->getJson('/api/v1/admin/orders/stats')->assertOk()->json('data');

        $this->assertEquals(130, $data['received']['month']);
        $this->assertEquals(130, $data['received']['today']);
        $this->assertEquals(100, $data['received']['prev']);
        $this->assertCount(14, $data['received']['daily']);
        $this->assertEquals(130, end($data['received']['daily']));
        $this->assertSame(3, $data['orders']['month']);      // the cancelled one doesn't count
        $this->assertSame(1, $data['orders']['prev']);
        $this->assertEquals(100, $data['average']['month']);
        $this->assertEquals(170, $data['to_collect']['amount']); // COD 100 + part-paid 70
        $this->assertSame(2, $data['to_collect']['orders']);

        $this->actingAsRole('customer');
        $this->getJson('/api/v1/admin/orders/stats')->assertForbidden();
    }

    public function test_staff_fix_contact_details_until_the_order_ships(): void
    {
        $number = $this->place('cod');
        $this->actingAsRole('staff');

        $this->patchJson("/api/v1/admin/orders/{$number}", ['ship_phone' => '097 232 4523', 'ship_address' => '#12, St 271, Sangkat Tuol Tumpung'])
            ->assertOk()
            ->assertJsonPath('data.shipping.phone', '0972324523')
            ->assertJsonPath('data.shipping.address', '#12, St 271, Sangkat Tuol Tumpung')
            ->assertJsonPath('customer_summary.orders', 1);

        $this->patchJson("/api/v1/admin/orders/{$number}", ['ship_phone' => '123'])->assertJsonValidationErrors('ship_phone');

        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => Order::CONFIRMED])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$number}", ['status' => Order::SHIPPED])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$number}", ['ship_name' => 'Someone else'])->assertJsonValidationErrors('ship_address');
    }

    public function test_sort_and_customer_summary(): void
    {
        $customer = $this->actingAsRole('customer');
        $address = $this->makeAddress($customer);
        $first = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$this->variantId, 1]], ['payment_method' => 'cod']))->json('data.number');
        $second = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$this->variantId, 2]], ['payment_method' => 'khqr']))->json('data.number');

        $this->actingAsRole('staff');
        $this->pay($second, 200);

        $this->getJson('/api/v1/admin/orders')->assertJsonPath('data.0.number', $second);
        $this->getJson('/api/v1/admin/orders?sort=oldest')->assertJsonPath('data.0.number', $first);
        $this->getJson('/api/v1/admin/orders?sort=total_asc')->assertJsonPath('data.0.number', $first);
        $this->getJson('/api/v1/admin/orders?sort=nope')->assertJsonValidationErrors('sort');

        $this->getJson("/api/v1/admin/orders/{$first}")
            ->assertJsonPath('customer_summary.orders', 2)
            ->assertJsonPath('customer_summary.spent', 200);
    }

    public function test_riel_payments_store_the_rate_used(): void
    {
        $number = $this->place();
        $this->actingAsRole('staff');

        // 205,000 riel at 4,100 = $50.00
        $this->pay($number, 0, ['method' => 'cash', 'currency' => 'KHR', 'amount_received' => 205000, 'exchange_rate' => 4100])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PARTIAL)
            ->assertJsonPath('data.amount_paid', 50)
            ->assertJsonPath('data.payments.0.amount', 50)
            ->assertJsonPath('data.payments.0.currency', 'KHR')
            ->assertJsonPath('data.payments.0.amount_received', 205000)
            ->assertJsonPath('data.payments.0.exchange_rate', 4100);

        // No rate sent: the shop's rate is used and stored with the payment
        config(['shop.khqr.khr_per_usd' => 4000]);
        $this->pay($number, 0, ['method' => 'cash', 'currency' => 'KHR', 'amount_received' => 100000])
            ->assertJsonPath('data.payments.1.exchange_rate', 4000)
            ->assertJsonPath('data.payments.1.amount', 25);

        // KHR without the riel amount is refused
        $this->postJson("/api/v1/admin/orders/{$number}/payments", ['method' => 'cash', 'currency' => 'KHR'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_received');
    }

    public function test_riel_rounding_does_not_make_an_order_overpaid(): void
    {
        $number = $this->place();
        $this->actingAsRole('staff');
        $this->pay($number, 30.01);

        // Balance $69.99 x 4,100 = 286,959 riel, rounded to 287,000 = $70.00: counts as the exact balance
        $this->pay($number, 0, ['method' => 'khqr', 'currency' => 'KHR', 'amount_received' => 287000, 'exchange_rate' => 4100])
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('data.payments.1.amount', 69.99);
    }

    public function test_one_bank_transaction_is_recorded_only_once(): void
    {
        $first = $this->place();
        $second = $this->place();
        $this->actingAsRole('staff');

        $this->pay($first, 10, ['method' => 'bank_transfer', 'provider' => 'ABA', 'reference' => ' FT2611100001 '])
            ->assertCreated()
            ->assertJsonPath('data.payments.0.provider', 'aba')
            ->assertJsonPath('data.payments.0.reference', 'FT2611100001');

        // Same transaction (any letter case) on any order is refused, with the order that has it
        $response = $this->pay($second, 10, ['method' => 'bank_transfer', 'provider' => 'aba', 'reference' => 'ft2611100001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');
        $this->assertStringContainsString($first, $response->json('errors.reference.0'));

        // Same number from another bank is a different transaction; no reference is never checked
        $this->pay($second, 10, ['method' => 'bank_transfer', 'provider' => 'aclb', 'reference' => 'FT2611100001'])->assertCreated();
        $this->pay($second, 5)->assertCreated();
        $this->pay($second, 5)->assertCreated();
    }

    public function test_the_payer_is_not_the_owner_of_the_order(): void
    {
        $number = $this->place();
        $owner = Order::where('number', $number)->first()->user;

        $this->actingAsRole('staff');
        $this->pay($number, 100, ['method' => 'khqr', 'payer_account' => 'aunt@wing', 'payer_name' => 'Chan Sophea'])
            ->assertJsonPath('data.customer.id', $owner->id)
            ->assertJsonPath('data.payments.0.payer_name', 'Chan Sophea');

        // Still the same owner; a stranger still can't see it
        $this->assertSame($owner->id, Order::where('number', $number)->value('user_id'));
        $this->actingAsRole('customer');
        $this->getJson("/api/v1/orders/{$number}")->assertNotFound();
    }
}
