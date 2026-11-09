<?php

namespace Tests\Feature\Concerns;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Small helpers shared by the catalog feature tests.
 * Tests run on an in-memory SQLite database (see phpunit.xml), never on the real PostgreSQL DB.
 */
trait InteractsWithCatalog
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /** Create a user with a role and authenticate as them for the next requests. */
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeCategory(array $attributes = []): Category
    {
        static $n = 0;
        $n++;

        return Category::create($attributes + [
            'name'       => "Category {$n}",
            'slug'       => "category-{$n}",
            'sort_order' => $n,
        ]);
    }

    protected function makeProduct(array $attributes = [], array $variants = [['price' => 100]]): Product
    {
        static $n = 0;
        $n++;

        if (array_key_exists('brand', $attributes)) {
            $name = $attributes['brand'];
            unset($attributes['brand']);
            $attributes['brand_id'] = $name ? Brand::findOrCreateByName($name)->id : null;
        }

        $product = Product::create($attributes + [
            'category_id' => $attributes['category_id'] ?? $this->makeCategory()->id,
            'series'      => "Series {$n}",
            'name'        => "Product {$n}",
            'slug'        => "product-{$n}",
            'is_active'   => true,
        ]);

        foreach ($variants as $i => $variant) {
            ProductVariant::create($variant + [
                'product_id'     => $product->id,
                'title'          => "{$product->name} · v{$i}",
                'config'         => [],
                'stock_quantity' => 5,
                'in_stock'       => ($variant['stock_quantity'] ?? 5) > 0,
            ]);
        }

        return $product;
    }

    /** Names of the products in a list response, in order. */
    protected function names($response): array
    {
        return collect($response->json('data'))->pluck('name')->all();
    }
}
