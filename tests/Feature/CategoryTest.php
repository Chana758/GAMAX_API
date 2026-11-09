<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_public_list_is_sorted_by_sort_order(): void
    {
        Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'sort_order' => 3]);
        Category::create(['name' => 'Graphic Cards', 'slug' => 'graphic-cards', 'sort_order' => 1]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'graphic-cards')
            ->assertJsonPath('data.1.slug', 'monitors');

        $this->getJson('/api/v1/categories/monitors')->assertOk()->assertJsonPath('data.name', 'Monitors');
        $this->getJson('/api/v1/categories/nope')->assertNotFound();
    }

    public function test_admin_creates_category_with_generated_slug(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/admin/categories', ['name' => 'Power Supply'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'power-supply');

        $this->postJson('/api/v1/admin/categories', ['name' => 'Power Supply'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_admin_search_is_case_insensitive(): void
    {
        $this->actingAsRole('admin');
        Category::create(['name' => 'Laptop', 'slug' => 'laptop']);

        $this->getJson('/api/v1/admin/categories?search=LAP')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guests_and_customers_cannot_manage_categories(): void
    {
        $this->postJson('/api/v1/admin/categories', ['name' => 'X'])->assertUnauthorized();

        $this->actingAsRole('customer');
        $this->postJson('/api/v1/admin/categories', ['name' => 'X'])->assertForbidden();
    }

    public function test_staff_can_edit_but_not_delete(): void
    {
        $category = $this->makeCategory();
        $this->actingAsRole('staff');

        $this->putJson("/api/v1/admin/categories/{$category->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/v1/admin/categories/{$category->id}")->assertForbidden();
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $product = $this->makeProduct();
        $this->actingAsRole('admin');

        $this->deleteJson("/api/v1/admin/categories/{$product->category_id}")->assertUnprocessable();

        $empty = $this->makeCategory();
        $this->deleteJson("/api/v1/admin/categories/{$empty->id}")->assertOk();
        $this->assertModelMissing($empty);
    }
}
