<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_hidden_variant_is_not_sold_but_admin_still_sees_it(): void
    {
        $product = $this->makeProduct(['slug' => 'gpu'], [
            ['price' => 300, 'config' => ['edition' => 'Standard']],
            ['price' => 100, 'config' => ['edition' => 'Old'], 'compare_price' => 150],
        ]);
        $old = $product->variants()->where('price', 100)->first();

        $this->putJson("/api/v1/admin/variants/{$old->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/products/gpu')
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.price_from', 300);
        $this->assertSame([], $this->names($this->getJson('/api/v1/products?on_sale=1')), 'a hidden discount is not a deal');
        $this->assertSame([], $this->names($this->getJson('/api/v1/products?max_price=200')));

        $this->getJson("/api/v1/admin/products/{$product->id}/variants")->assertJsonCount(2, 'data');
    }

    public function test_duplicate_options_are_rejected_unless_the_other_variant_is_hidden(): void
    {
        $product = $this->makeProduct([], [['price' => 100, 'config' => ['RAM' => '16GB', 'Storage' => '512GB']]]);
        $first = $product->variants()->first();

        // Same options in another case/order -> rejected
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => ['storage:' => '512gb', 'ram' => '16GB'], 'price' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors('config');

        // A typed title already used by a visible variant -> rejected
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => ['ram' => '32GB'], 'price' => 120, 'title' => strtoupper($first->title),
        ])->assertUnprocessable()->assertJsonValidationErrors('title');

        // Hide the old one, then the same options are allowed again
        $this->putJson("/api/v1/admin/variants/{$first->id}", ['is_active' => false])->assertOk();
        $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'config' => ['ram' => '16GB', 'storage' => '512GB'], 'price' => 120,
        ])->assertCreated();

        // ...but the hidden one cannot be shown again while it duplicates a visible one
        $this->putJson("/api/v1/admin/variants/{$first->id}", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('config');
    }

    public function test_editing_price_of_a_variant_keeps_working(): void
    {
        $product = $this->makeProduct([], [
            ['price' => 100, 'config' => ['ram' => '16GB']],
            ['price' => 200, 'config' => ['ram' => '32GB']],
        ]);
        $variant = $product->variants()->where('price', 100)->first();

        $this->putJson("/api/v1/admin/variants/{$variant->id}", ['price' => 110, 'config' => ['ram' => '16GB']])
            ->assertOk()->assertJsonPath('data.price', 110);
    }

    public function test_database_refuses_two_visible_variants_with_the_same_title(): void
    {
        $product = $this->makeProduct([], []);
        $row = ['product_id' => $product->id, 'title' => 'Same', 'config' => '{}', 'price' => 1, 'stock_quantity' => 1];

        DB::table('product_variants')->insert($row + ['is_active' => false]);
        DB::table('product_variants')->insert($row + ['is_active' => true]);   // hidden copy is fine

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('product_variants')->insert($row + ['is_active' => true]);
    }

    public function test_database_refuses_negative_stock_and_bad_prices(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('CHECK constraints are added on PostgreSQL/MySQL only.');
        }

        $variant = $this->makeProduct([], [['price' => 100]])->variants()->first();

        foreach ([['stock_quantity' => -1], ['price' => -5], ['compare_price' => 90]] as $bad) {
            try {
                DB::transaction(fn () => DB::table('product_variants')->where('id', $variant->id)->update($bad));
                $this->fail('Saved an invalid value: ' . json_encode($bad));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], 'check_violation'); // PostgreSQL code
            }
        }

        $this->assertSame(5, ProductVariant::find($variant->id)->stock_quantity);
    }
}
