<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAsRole('admin');
    }

    public function test_create_generates_title_and_syncs_stock(): void
    {
        $product = $this->makeProduct(['name' => 'MacBook Pro 14'], []);

        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config'         => ['chip' => 'M3 Pro', 'ram' => '18GB'],
            'price'          => 1999.99,
            'stock_quantity' => 0,
        ])->assertCreated()
            ->assertJsonPath('data.title', 'MacBook Pro 14 · M3 Pro 18GB')
            ->assertJsonPath('data.in_stock', false);
    }

    public function test_compare_price_must_be_higher_than_price(): void
    {
        $product = $this->makeProduct([], []);

        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => [], 'price' => 500, 'compare_price' => 400,
        ])->assertUnprocessable()->assertJsonValidationErrors('compare_price');

        $variant = $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => [], 'price' => 500, 'compare_price' => 625,
        ])->assertCreated()->assertJsonPath('data.discount_percent', 20)->json('data');

        // Update uses the saved price when only compare_price is sent
        $this->putJson("/api/v1/admin/variants/{$variant['id']}", ['compare_price' => 450])
            ->assertUnprocessable()->assertJsonValidationErrors('compare_price');
    }

    public function test_stock_update_flips_in_stock(): void
    {
        $product = $this->makeProduct([], [['price' => 100, 'stock_quantity' => 3]]);
        $variant = $product->variants()->first();

        $this->putJson("/api/v1/admin/variants/{$variant->id}", ['stock_quantity' => 0])
            ->assertOk()->assertJsonPath('data.in_stock', false);

        $this->putJson("/api/v1/admin/variants/{$variant->id}", ['stock_quantity' => 7])
            ->assertOk()->assertJsonPath('data.in_stock', true);
    }

    public function test_sku_must_be_unique(): void
    {
        $product = $this->makeProduct([], [['price' => 100, 'sku' => 'GMX-001']]);

        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => [], 'price' => 120, 'sku' => 'GMX-001',
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_list_and_delete_variant(): void
    {
        $product = $this->makeProduct([], [['price' => 100], ['price' => 200]]);

        $this->getJson("/api/v1/admin/products/{$product->id}/variants")->assertOk()->assertJsonCount(2, 'data');

        $id = $product->variants()->first()->id;
        $this->deleteJson("/api/v1/admin/variants/{$id}")->assertOk();
        $this->assertDatabaseMissing('product_variants', ['id' => $id]);
    }

    public function test_staff_cannot_delete_variant(): void
    {
        $product = $this->makeProduct();
        $this->actingAsRole('staff');

        $this->deleteJson("/api/v1/admin/variants/{$product->variants()->first()->id}")->assertForbidden();
    }
}
