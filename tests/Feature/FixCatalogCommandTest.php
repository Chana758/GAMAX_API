<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fixture copies the problems found in the real catalog on 2026-11-09
 * (same names, slugs, options and SKUs), so the command is tested on realistic data.
 */
class FixCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['graphic-cards' => 'Graphic Cards', 'laptop' => 'Laptop', 'monitors' => 'Monitors', 'power-supply' => 'Power Supply'] as $slug => $name) {
            $this->categories[$slug] = Category::create(['name' => $name, 'slug' => $slug])->id;
        }

        $this->product('laptop', 'Apple', 'MacBook Pro 16"', 'macbook-pro-16', [
            ['acBook Pro 16"', ['Chip:' => 'M3 Pro', 'RAM' => '18GB', 'Storage:' => '512GB'], 2499.99],
            ['MacBook Pro 16"', ['Chip:' => 'M3 Max', 'RAM' => '36GB', 'Storage:' => '1TB'], 2499.99],
        ]);
        $this->product('laptop', 'Apple', 'MacBook Air M3', 'macbook', [
            ['MacBook Air M3', ['Chip:' => 'M3', 'Ram' => '16GB', 'Storage' => '512GB'], 1499.99],
            ['MacBook Air M3 ·', ['RAM' => '16GB', 'Storage' => '256', 'Chip:' => 'M3'], 1299.99],
        ]);
        $this->product('laptop', 'ASUS', 'ASUS ROG Zephyrus G14', 'asus-rog-zephyrus-g14', [
            ['SUS ROG Zephyrus G14', ['Ram' => '16GB', 'Storage' => '512GB'], 1649.99],
            ['ASUS ROG Zephyrus G14', ['Ram' => '32GB', 'storage' => '1T'], 1949.99],
            ['ASUS ROG Zephyrus G16', ['Ram' => '32GB', 'Storage' => '1TB'], 2599.99],
        ]);
        $this->product('monitors', 'Samsung', 'Samsung Odyssey G9 49"', 'samsung-odyssey-g9-49', [
            ['Samsung Odyssey G9 49" · QLED 120Hz', ['panel' => 'QLED', 'refresh' => '120Hz'], 1199.99],
            // Out-of-stock copy: hidden by the 2026_11_09_110300 migration (same title as the next one)
            ['Samsung Odyssey G9 49" · QLED 240Hz', ['panel' => 'QLED', 'refresh' => '240Hz'], 1399.99, 0, null, false],
            ['Samsung Odyssey G9 49" · QLED 240Hz', ['panel' => 'QLED', 'refresh' => '240Hz'], 1499.99],
        ]);
        $this->product('graphic-cards', 'NVIDIA', 'NVIDIA GeForce RTX 4060 Ti 🔥 Deal', 'nvidia-geforce-rtx-4060-ti-deal', [
            ['NVIDIA GeForce RTX 4060 Ti 🔥 Deal · 12GB Standard', ['RAM' => '8GB', 'edition' => 'Standard'], 449.99],
            ['NVIDIA GeForce RTX 4060 Ti 🔥 Deal · 8GB OC', ['RAM' => '8GB', 'edition' => 'OC'], 479.99],
            ['NVIDIA GeForce RTX 4060 Ti 🔥 Deal · 8GB Standard', ['RAM' => '8GB', 'edition' => 'Standard'], 499.99],
        ]);
        $this->product('graphic-cards', 'NVIDIA', 'NVIDIA GeForce RTX 4070 Ti', 'nvidia-geforce-rtx-4070-ti', [
            ['NVIDIA GeForce RTX 4070 Ti · 12GB OC', ['RAM' => '12GB', '12GB' => 'OC'], 849.99],
        ]);
        $this->product('graphic-cards', 'NVIDIA', 'NVIDIA GeForce RTX 4090', 'nvidia-geforce-rtx-4090', [
            ['NVIDIA GeForce RTX 4090 · 24GB', ['Ram' => '24GB', 'edition' => 'Standard', 'Storage' => '1T'], 1599.99],
            ['NVIDIA GeForce RTX 4090 · 24 White 512GB', ['Ram' => '24', 'edition' => 'White', 'Storage' => '512GB'], 1749.99],
            ['NVIDIA GeForce RTX 4090 · 128 2T Gaming Trio 5060', ['Ram' => '128', 'Storage' => '2T', 'edition' => 'Gaming Trio'], 1699.99],
        ]);
        $this->product('power-supply', 'Corsair', 'Corsair RMx 🔥 Dea', 'macbook-air-m3', [
            ['MacBook Air M3 · 24GB 512GB', ['wattage' => '650W'], 99.99, 10, 'MBA-M3-24-512'],
            ['Corsair RMx 🔥 Dea · 750W', ['wattage' => '750W'], 119.99],
        ]);
        $this->product('monitors', 'LG', 'LG UltraGear 27" 4K Monitor', 'hp-spectre-x360-14', [
            ['HP Spectre x360 14" · 16GB 512GB', ['ram' => '16GB', 'storage' => '512GB'], 1499.99, 10, 'SPECTRE-16-512'],
            ['LG UltraGear 27" 4K Monitor · 144Hz IPS', ['refresh' => '144Hz', 'panel' => 'IPS'], 699.99, 10, 'SPECTRE-32-1TB'],
            ['LG UltraGear 27" 4K Monitor · 160Hz IPS', ['refresh' => '160Hz', 'panel' => 'IPS'], 749.99],
        ]);
        $this->product('monitors', 'Dell', 'Dell UltraSharp 32" 4K', 'dell-ultrasharp-32-4k', [
            ['Dell UltraSharp 32" 4K · DisplayPort IPS', ['ports' => 'DisplayPort', 'panel' => 'IPS'], 799.99],
        ]);
        $this->product('laptop', null, 'Dell XPS 15', 'dell-xps-15', [
            ['Dell XPS 15 · 16GB 512GB', ['ram' => '16GB', 'storage' => '512GB'], 1899.99, 10, 'XPS15-16-512'],
            ['Dell XPS 15 · 32GB 1TB', ['ram' => '32GB', 'storage' => '1TB'], 2299.99, 10, 'XPS15-32-1TB'],
        ]);
        $this->product('power-supply', 'Seasonic', 'Seasonic FOCUS GX 🔥 Deal', 'seasonic-focus-gx-deal', [
            ['Seasonic FOCUS GX 🔥 Deal · 650W', ['wattage' => '650W'], 89.99],
            ['Seasonic FOCUS GX 🔥 Deal · 850W', ['Wattage' => '850W'], 109.99],
        ]);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('catalog:fix', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_fixes_products(): void
    {
        $this->artisan('catalog:fix')->assertSuccessful();

        $slugs = Product::pluck('slug', 'name');
        $this->assertSame('macbook-air-m3', $slugs['MacBook Air M3']);
        $this->assertSame('corsair-rmx', $slugs['Corsair RMx']);
        $this->assertSame('lg-ultragear-27-4k-monitor', $slugs['LG UltraGear 27" 4K Monitor']);
        $this->assertSame('nvidia-geforce-rtx-4060-ti', $slugs['NVIDIA GeForce RTX 4060 Ti']);
        $this->assertSame('seasonic-focus-gx', $slugs['Seasonic FOCUS GX']);
        $this->assertSame('dell-ultrasharp-32-4k', $slugs['Dell UltraSharp 32" 4K']);
        $this->assertSame(0, Product::where('name', 'like', '%🔥%')->count());

        $this->assertSame('Dell', Product::where('name', 'Dell XPS 15')->first()->brand->name);
    }

    public function test_fixes_variants(): void
    {
        $this->artisan('catalog:fix')->assertSuccessful();

        // Leftover laptop variant inside the LG monitor is deleted, the real monitor variants stay
        $this->assertDatabaseMissing('product_variants', ['title' => 'HP Spectre x360 14" · 16GB 512GB']);
        $this->assertSame(2, Product::where('slug', 'lg-ultragear-27-4k-monitor')->first()->variants()->count());

        // Corsair's 650W variant keeps its price/options, gets a correct title, loses the MacBook SKU
        $corsair = ProductVariant::where('price', 99.99)->first();
        $this->assertSame('Corsair RMx · 650W', $corsair->title);
        $this->assertNull($corsair->sku);
        $this->assertNull(ProductVariant::where('title', 'LG UltraGear 27" 4K Monitor · 144Hz IPS')->value('sku'));
        $this->assertSame('XPS15-16-512', ProductVariant::where('title', 'Dell XPS 15 · 16GB 512GB')->value('sku'));

        $titles = ProductVariant::pluck('title')->all();
        foreach ([
            'MacBook Pro 16" · M3 Pro 18GB 512GB',
            'MacBook Pro 16" · M3 Max 36GB 1TB',
            'MacBook Air M3 · 16GB 512GB',
            'MacBook Air M3 · 16GB 256GB',
            'ASUS ROG Zephyrus G14 · 16GB 512GB',
            'NVIDIA GeForce RTX 4060 Ti · 12GB Standard',   // clashing options -> only the 🔥 mark removed
            'NVIDIA GeForce RTX 4060 Ti · 8GB OC',
            'NVIDIA GeForce RTX 4090 · 24GB Standard',
            'NVIDIA GeForce RTX 4090 · 24GB White',
            'NVIDIA GeForce RTX 4090 · 24GB Gaming Trio',
            'Seasonic FOCUS GX · 850W',
            'Corsair RMx · 750W',
        ] as $expected) {
            $this->assertContains($expected, $titles);
        }

        // Clashing variants are reported, not renamed; hidden variants are left alone
        $this->assertContains('ASUS ROG Zephyrus G16', $titles);
        $this->assertSame(2, ProductVariant::where('title', 'Samsung Odyssey G9 49" · QLED 240Hz')->count());
        $this->assertFalse(ProductVariant::where('price', 1399.99)->value('is_active'));

        // Options: clean keys, units, no storage on graphics cards
        $this->assertSame(['chip' => 'M3 Pro', 'ram' => '18GB', 'storage' => '512GB'],
            ProductVariant::where('price', 2499.99)->orderBy('id')->first()->config);
        $this->assertSame(['ram' => '12GB', 'edition' => 'OC'], ProductVariant::where('price', 849.99)->first()->config);
        $this->assertSame(['ram' => '32GB', 'storage' => '1TB'], ProductVariant::where('price', 1949.99)->first()->config);
        $this->assertSame(['wattage' => '850W'], ProductVariant::where('price', 109.99)->first()->config);
        $this->assertSame(['ram' => '24GB', 'edition' => 'Gaming Trio'], ProductVariant::where('price', 1699.99)->first()->config);
    }

    public function test_reports_what_needs_a_person(): void
    {
        $this->artisan('catalog:fix')
            ->expectsOutputToContain('Needs your decision')
            ->doesntExpectOutputToContain('Samsung Odyssey G9') // the duplicate is hidden
            ->expectsOutputToContain('NVIDIA GeForce RTX 4060 Ti')
            ->expectsOutputToContain('ASUS ROG Zephyrus G14')
            ->expectsOutputToContain('same price $2499.99')
            ->assertSuccessful();
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->artisan('catalog:fix')->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('catalog:fix')->expectsOutputToContain('Nothing to fix')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_optional_skus_and_featured(): void
    {
        $this->artisan('catalog:fix', ['--with-skus' => true, '--feature' => 'corsair-rmx,macbook-air-m3,nope'])
            ->expectsOutputToContain('no product with slug "nope"')
            ->assertSuccessful();

        $this->assertSame(0, ProductVariant::whereNull('sku')->count());
        $this->assertSame('XPS15-16-512', ProductVariant::where('title', 'Dell XPS 15 · 16GB 512GB')->value('sku'));
        $this->assertMatchesRegularExpression('/^GMX-\d{3}-\d{4}$/', ProductVariant::where('title', 'Corsair RMx · 650W')->value('sku'));
        $this->assertEqualsCanonicalizing(['Corsair RMx', 'MacBook Air M3'], Product::where('is_featured', true)->pluck('name')->all());
    }

    // ---------------------------------------------------------------- helpers

    private function product(string $category, ?string $brand, string $name, string $slug, array $variants): void
    {
        $product = Product::create([
            'category_id' => $this->categories[$category],
            'brand_id'    => $brand ? Brand::findOrCreateByName($brand)->id : null,
            'series'      => $name,
            'name'        => $name,
            'slug'        => $slug,
            'is_active'   => true,
        ]);

        foreach ($variants as $row) {
            [$title, $config, $price, $stock, $sku, $active] = $row + [3 => 10, 4 => null, 5 => true];

            $product->variants()->create([
                'title'          => $title,
                'config'         => $config,
                'price'          => $price,
                'stock_quantity' => $stock,
                'in_stock'       => $stock > 0,
                'sku'            => $sku,
                'is_active'      => $active,
            ]);
        }
    }

    private function snapshot(): array
    {
        return [
            Product::orderBy('id')->get(['id', 'name', 'slug', 'brand_id', 'is_featured'])->toArray(),
            ProductVariant::orderBy('id')->get(['id', 'title', 'config', 'sku'])->toArray(),
        ];
    }
}
