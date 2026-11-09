<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_brand_crud(): void
    {
        $this->actingAsRole('admin');

        $id = $this->postJson('/api/v1/admin/brands', ['name' => 'Gigabyte'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'gigabyte')
            ->json('data.id');

        $this->postJson('/api/v1/admin/brands', ['name' => 'GIGABYTE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->getJson('/api/v1/admin/brands?search=giga')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/admin/brands/{$id}", ['name' => 'GIGABYTE AORUS'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'gigabyte-aorus');

        $this->deleteJson("/api/v1/admin/brands/{$id}")->assertOk();
        $this->assertDatabaseCount('brands', 0);
    }

    public function test_renaming_a_brand_renames_it_on_products(): void
    {
        $product = $this->makeProduct(['slug' => 'mbp', 'brand' => 'MacBook']);
        $this->actingAsRole('admin');

        $this->putJson("/api/v1/admin/brands/{$product->brand_id}", ['name' => 'Apple'])->assertOk();

        $this->getJson('/api/v1/products/mbp')->assertJsonPath('data.brand', 'Apple');
    }

    public function test_brand_with_products_cannot_be_deleted(): void
    {
        $product = $this->makeProduct(['brand' => 'ASUS']);
        $this->actingAsRole('admin');

        $this->deleteJson("/api/v1/admin/brands/{$product->brand_id}")->assertUnprocessable();
        $this->assertSame(1, Brand::count());
    }

    public function test_staff_cannot_delete_and_customers_cannot_view(): void
    {
        $brand = Brand::create(['name' => 'Corsair', 'slug' => 'corsair']);

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/admin/brands')->assertOk();
        $this->deleteJson("/api/v1/admin/brands/{$brand->id}")->assertForbidden();

        $this->actingAsRole('customer');
        $this->getJson('/api/v1/admin/brands')->assertForbidden();
    }
}
