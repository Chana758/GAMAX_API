<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithCatalog;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use InteractsWithCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ---------- Public API ----------

    public function test_public_list_hides_inactive_products_and_shows_price_from(): void
    {
        $this->makeProduct(['name' => 'Visible'], [['price' => 300], ['price' => 250]]);
        $this->makeProduct(['name' => 'Hidden', 'is_active' => false]);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame(['Visible'], $this->names($response));
        $response->assertJsonPath('data.0.price_from', 250)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_inactive_product_page_is_404(): void
    {
        $this->makeProduct(['slug' => 'secret', 'is_active' => false]);

        $this->getJson('/api/v1/products/secret')->assertNotFound();
    }

    public function test_filters_category_brand_sale_featured_search_and_price(): void
    {
        $laptop = $this->makeCategory(['slug' => 'laptop']);

        $this->makeProduct(['name' => 'MacBook Pro 14', 'brand' => 'Apple', 'category_id' => $laptop->id, 'is_featured' => true], [['price' => 1999]]);
        $this->makeProduct(['name' => 'ROG Zephyrus', 'series' => 'Zephyrus G14', 'brand' => 'ASUS', 'category_id' => $laptop->id], [['price' => 1649]]);
        $this->makeProduct(['name' => 'RTX 4060 Ti', 'brand' => 'NVIDIA'], [['price' => 449, 'compare_price' => 562]]);

        $this->assertEqualsCanonicalizing(['MacBook Pro 14', 'ROG Zephyrus'], $this->names($this->getJson('/api/v1/products?category=laptop')));
        $this->assertSame(['MacBook Pro 14'], $this->names($this->getJson('/api/v1/products?brand=apple')));
        $this->assertEqualsCanonicalizing(['MacBook Pro 14', 'RTX 4060 Ti'], $this->names($this->getJson('/api/v1/products?brand=Apple,NVIDIA')));
        $this->assertSame(['RTX 4060 Ti'], $this->names($this->getJson('/api/v1/products?on_sale=1')));
        $this->assertSame(['MacBook Pro 14'], $this->names($this->getJson('/api/v1/products?featured=1')));
        $this->assertSame(['ROG Zephyrus'], $this->names($this->getJson('/api/v1/products?search=zephyrus')), 'search matches series');
        $this->assertSame(['ROG Zephyrus'], $this->names($this->getJson('/api/v1/products?search=asus')), 'search matches brand');
        $this->assertSame(['RTX 4060 Ti'], $this->names($this->getJson('/api/v1/products?max_price=500')));
        $this->assertSame(['RTX 4060 Ti', 'ROG Zephyrus', 'MacBook Pro 14'], $this->names($this->getJson('/api/v1/products?sort=price_asc')));
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->makeProduct(['name' => 'Corsair RM850x']);

        $this->assertSame([], $this->names($this->getJson('/api/v1/products?search=%25')));
    }

    public function test_product_page_returns_brand_name_variants_and_gallery(): void
    {
        $product = $this->makeProduct(['slug' => 'mbp-14', 'brand' => 'Apple', 'image' => '/storage/products/cover.png']);
        $product->images()->create(['path' => '/storage/products/side.png', 'sort_order' => 0]);

        $this->getJson('/api/v1/products/mbp-14')
            ->assertOk()
            ->assertJsonPath('data.brand', 'Apple')
            ->assertJsonPath('data.brand_slug', 'apple')
            ->assertJsonPath('data.images.0.path', '/storage/products/side.png')
            ->assertJsonCount(1, 'data.variants');
    }

    public function test_brands_endpoint_lists_only_brands_with_visible_products(): void
    {
        $this->makeProduct(['brand' => 'Apple']);
        $this->makeProduct(['brand' => 'Intel', 'is_active' => false]);
        Brand::create(['name' => 'Empty', 'slug' => 'empty']);

        $this->getJson('/api/v1/brands')->assertOk()->assertExactJson(['success' => true, 'data' => ['Apple']]);
    }

    // ---------- Admin API ----------

    public function test_admin_creates_product_with_new_brand_and_gallery(): void
    {
        $this->actingAsRole('admin');
        $category = $this->makeCategory();

        $response = $this->postJson('/api/v1/admin/products', [
            'category_id' => $category->id,
            'series'      => 'RTX 4070',
            'brand'       => ' msi ',
            'name'        => 'MSI RTX 4070 Gaming X',
            'is_featured' => true,
            'images'      => ['/storage/products/a.png', '/storage/products/b.png'],
        ])->assertCreated();

        $response->assertJsonPath('data.slug', 'msi-rtx-4070-gaming-x')
            ->assertJsonPath('data.brand', 'msi')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonCount(2, 'data.images');

        // Same brand typed in another case is reused, not duplicated
        $this->postJson('/api/v1/admin/products', [
            'category_id' => $category->id, 'series' => 'X', 'brand' => 'MSI', 'name' => 'MSI Other',
        ])->assertCreated();
        $this->assertSame(1, Brand::where('slug', 'msi')->count());
    }

    public function test_admin_rejects_image_paths_that_were_not_uploaded(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/admin/products', [
            'category_id' => $this->makeCategory()->id,
            'series'      => 'X',
            'name'        => 'Bad image',
            'image'       => '/etc/passwd',
            'images'      => ['https://evil.example/x.png'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['image', 'images.0']);
    }

    public function test_updating_gallery_replaces_it_and_deletes_removed_files(): void
    {
        Storage::fake('public');
        $this->actingAsRole('admin');

        $upload = fn () => $this->post('/api/v1/admin/uploads/image', [
            'image' => UploadedFile::fake()->image('p.png', 50, 50),
        ])->assertCreated()->json('data.path');

        [$cover, $one, $two] = [$upload(), $upload(), $upload()];
        $product = $this->makeProduct(['image' => $cover]);

        $this->putJson("/api/v1/admin/products/{$product->id}", ['images' => [$one, $two]])
            ->assertOk()->assertJsonCount(2, 'data.images');

        $this->putJson("/api/v1/admin/products/{$product->id}", ['images' => [$two]])
            ->assertOk()->assertJsonPath('data.images.0.path', $two);

        Storage::disk('public')->assertMissing(str_replace('/storage/', '', $one));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $two));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $cover));

        // A file used by the gallery cannot be deleted through the upload API
        $this->deleteJson('/api/v1/admin/uploads/image', ['path' => $two])->assertUnprocessable();

        // Not sending "images" keeps the gallery untouched
        $this->putJson("/api/v1/admin/products/{$product->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonCount(1, 'data.images');
    }

    public function test_product_with_variants_cannot_be_deleted_and_staff_cannot_delete(): void
    {
        $product = $this->makeProduct();

        $this->actingAsRole('staff');
        $this->deleteJson("/api/v1/admin/products/{$product->id}")->assertForbidden();

        $this->actingAsRole('admin');
        $this->deleteJson("/api/v1/admin/products/{$product->id}")->assertUnprocessable();

        $product->variants()->delete();
        $this->deleteJson("/api/v1/admin/products/{$product->id}")->assertOk();
        $this->assertModelMissing($product);
    }

    public function test_admin_list_filters_and_includes_gallery_for_the_edit_form(): void
    {
        $this->actingAsRole('staff');
        $product = $this->makeProduct(['name' => 'Gallery product', 'brand' => 'Apple']);
        $product->images()->create(['path' => '/storage/products/g.png']);
        $this->makeProduct(['name' => 'Other', 'is_active' => false]);

        $this->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/admin/products?brand=apple')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.images.0.path', '/storage/products/g.png');

        $this->getJson('/api/v1/admin/products?is_active=0')->assertJsonPath('data.0.name', 'Other');
    }

    public function test_customers_cannot_use_the_admin_product_api(): void
    {
        $this->actingAsRole('customer');

        $this->getJson('/api/v1/admin/products')->assertForbidden();
        $this->post('/api/v1/admin/uploads/image', ['image' => UploadedFile::fake()->image('x.png')])->assertForbidden();
    }
}
