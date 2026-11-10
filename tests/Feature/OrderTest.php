<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\Feature\Concerns\InteractsWithOrders;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase, InteractsWithCatalog, InteractsWithOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        // Known rules for the tests (the real file holds the shop's rates)
        config([
            'shop.free_shipping_over' => 50,
            'shop.zones.capital'      => ['shipping_fee' => 2, 'cod_fee' => 0, 'delivery_days' => '1-3'],
            'shop.zones.far'          => ['shipping_fee' => 7, 'cod_fee' => 3, 'delivery_days' => '3-5'],
            'shop.payment_methods'    => ['cod'],
            'shop.cod_max_total'      => null,
            'shop.cod_excluded_provinces' => [],
            'shop.max_quantity_per_item'  => 10,
        ]);
    }

    public function test_settings_are_public(): void
    {
        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.payment_methods', ['cod'])
            ->assertJsonPath('data.free_shipping_over', 50)
            ->assertJsonPath('data.provinces.0.name', 'Phnom Penh')
            ->assertJsonPath('data.provinces.0.shipping_fee', 2);
    }

    public function test_guest_cannot_order(): void
    {
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_quote_uses_database_prices_and_province_fees(): void
    {
        $user = $this->actingAsRole('customer');
        $far = $this->makeAddress($user, ['province' => 'Mondulkiri', 'is_default' => false]);
        $cheap = $this->firstVariantId($this->makeProduct([], [['price' => 19.99]]));

        // A price sent by the browser is ignored
        $body = $this->checkout($far->id, [[$cheap, 2]]);
        $body['items'][0]['price'] = 0.01;

        $this->postJson('/api/v1/orders/quote', $body)
            ->assertOk()
            ->assertJsonPath('data.subtotal', 39.98)
            ->assertJsonPath('data.shipping_fee', 7)
            ->assertJsonPath('data.cod_fee', 3)
            ->assertJsonPath('data.total', 49.98)
            ->assertJsonPath('data.delivery_days', '3-5');

        // 3 x 19.99 = 59.97 reaches the free-shipping amount; the COD fee stays
        $this->postJson('/api/v1/orders/quote', $this->checkout($far->id, [[$cheap, 3]]))
            ->assertOk()
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.total', 62.97);

        $this->assertSame(0, Order::count());
    }

    public function test_placing_an_order_saves_a_copy_and_takes_the_stock(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $product = $this->makeProduct(['image' => '/storage/products/a.jpg'], [
            ['price' => 1299.99, 'stock_quantity' => 2, 'sku' => 'GMX-A-1', 'config' => ['ram' => '16GB']],
        ]);
        $variantId = $this->firstVariantId($product);

        $response = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 2]], [
            'note'           => 'Please call before arriving',
            'expected_total' => 2599.98,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', Order::PENDING_CONFIRMATION)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.total', 2599.98)
            ->assertJsonPath('data.shipping.phone', '012345678')
            ->assertJsonPath('data.items.0.unit_price', 1299.99)
            ->assertJsonPath('data.items.0.product_slug', $product->slug)
            ->assertJsonPath('data.can_cancel', true);

        $this->assertMatchesRegularExpression('/^GMX-\d{6}-[2-9A-HJ-NP-Z]{4}$/', $response->json('data.number'));

        $variant = ProductVariant::find($variantId);
        $this->assertSame(0, $variant->stock_quantity);
        $this->assertFalse($variant->in_stock);

        // The copy doesn't change when the catalog or the address changes later
        $variant->update(['title' => 'Renamed', 'price' => 1.00]);
        $address->update(['address' => 'Moved somewhere else entirely']);
        $item = Order::first()->items()->first();
        $this->assertSame('1299.99', $item->unit_price);
        $this->assertSame('GMX-A-1', $item->sku);
        $this->assertSame(['ram' => '16GB'], $item->config);
        $this->assertStringContainsString('St 271', Order::first()->ship_address);
    }

    public function test_cannot_buy_more_than_the_stock(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'stock_quantity' => 1]]));

        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 2]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors("items.{$variantId}");

        $this->assertSame(0, Order::count());
        $this->assertSame(1, ProductVariant::find($variantId)->stock_quantity);
    }

    public function test_hidden_or_unpublished_items_cannot_be_bought(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $hidden = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'is_active' => false]]));
        $offline = $this->firstVariantId($this->makeProduct(['is_active' => false]));

        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$hidden, 1], [$offline, 1], [999999, 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["items.{$hidden}", "items.{$offline}", 'items.999999']);
    }

    public function test_payment_method_address_and_quantity_are_checked(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $otherAddress = $this->makeAddress(User::factory()->create());
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'stock_quantity' => 50]]));

        // KHQR is not connected yet
        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 1]], ['payment_method' => 'khqr']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        // Someone else's address
        $this->postJson('/api/v1/orders', $this->checkout($otherAddress->id, [[$variantId, 1]]))
            ->assertUnprocessable()->assertJsonValidationErrors('address_id');

        // The same item twice is added up: 6 + 6 > 10
        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 6], [$variantId, 6]]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_cod_rules_from_config(): void
    {
        $user = $this->actingAsRole('customer');
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 600]]));

        config(['shop.cod_excluded_provinces' => ['Mondulkiri']]);
        $far = $this->makeAddress($user, ['province' => 'Mondulkiri']);
        $this->postJson('/api/v1/orders', $this->checkout($far->id, [[$variantId, 1]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        config(['shop.cod_excluded_provinces' => [], 'shop.cod_max_total' => 500]);
        $this->postJson('/api/v1/orders', $this->checkout($far->id, [[$variantId, 1]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_changed_total_is_shown_before_the_order_is_placed(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100]]));

        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 1]], ['expected_total' => 90]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('data.total', 100);

        $this->assertSame(0, Order::count());
        $this->assertSame(5, ProductVariant::find($variantId)->stock_quantity);
    }

    public function test_customer_sees_only_their_own_orders(): void
    {
        $other = User::factory()->create();
        $otherAddress = $this->makeAddress($other);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100]]));
        $this->actingAs($other);
        $theirs = $this->postJson('/api/v1/orders', $this->checkout($otherAddress->id, [[$variantId, 1]]))->json('data.number');

        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $mine = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 2]]))->json('data.number');

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $mine)
            ->assertJsonPath('data.0.item_count', 2);

        $this->getJson("/api/v1/orders/{$mine}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson("/api/v1/orders/{$theirs}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$theirs}/cancel")->assertNotFound();
    }

    public function test_cancelling_puts_the_stock_back_once(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100, 'stock_quantity' => 1]]));

        $number = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 1]]))->json('data.number');
        $this->assertFalse(ProductVariant::find($variantId)->in_stock);

        $this->postJson("/api/v1/orders/{$number}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::CANCELLED)
            ->assertJsonPath('data.cancelled_by', 'customer')
            ->assertJsonPath('data.can_cancel', false);

        $variant = ProductVariant::find($variantId);
        $this->assertSame(1, $variant->stock_quantity);
        $this->assertTrue($variant->in_stock);

        // A second cancel must not add the stock again
        $this->postJson("/api/v1/orders/{$number}/cancel")->assertUnprocessable();
        $this->assertSame(1, ProductVariant::find($variantId)->stock_quantity);
    }

    public function test_shipped_orders_cannot_be_cancelled_by_the_customer(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100]]));
        $number = $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 1]]))->json('data.number');

        Order::where('number', $number)->update(['status' => Order::SHIPPED]);

        $this->postJson("/api/v1/orders/{$number}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_an_ordered_variant_cannot_be_deleted(): void
    {
        $user = $this->actingAsRole('customer');
        $address = $this->makeAddress($user);
        $variantId = $this->firstVariantId($this->makeProduct([], [['price' => 100]]));
        $this->postJson('/api/v1/orders', $this->checkout($address->id, [[$variantId, 1]]))->assertCreated();

        $this->actingAsRole('admin');
        $this->deleteJson("/api/v1/admin/variants/{$variantId}")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);
    }
}
