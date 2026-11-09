<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One-time clean-up of catalog data typed by hand in the admin.
 *
 *   php artisan catalog:fix --dry-run      show every change, save nothing
 *   php artisan catalog:fix                apply (a JSON backup of the old rows is written first)
 *   php artisan catalog:fix --feature=macbook-pro-14,nvidia-geforce-rtx-4090 --with-skus
 *
 * Safe to run again: rows that are already clean are left alone.
 */
class FixCatalogData extends Command
{
    protected $signature = 'catalog:fix
                            {--dry-run : List the changes without saving them}
                            {--with-skus : Give every variant without a SKU one, e.g. GMX-020-0058}
                            {--feature= : Comma-separated product slugs to mark as Featured (Best Sellers)}';

    protected $description = 'Clean product names, slugs, brands, variant options/titles/SKUs and remove leftover variants.';

    /** SKU prefix => product name it belongs to. A SKU on another product was copied by mistake. */
    private const SKU_OWNERS = [
        'MBA-'     => 'MacBook Air',
        'SPECTRE-' => 'HP Spectre',
        'XPS15-'   => 'Dell XPS 15',
    ];

    /** Removes "🔥 Deal", "🔥 Dea", "🔥" from names and titles (the -% badge already shows a deal). */
    private const DEAL_MARK = '/\s*🔥\s*(?:deal\b|dea\b)?\s*/iu';

    private array $changes = [];

    private array $decisions = [];

    public function __construct(private readonly ProductVariantService $variants)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->changes = $this->decisions = []; // the same instance can run twice (tests, Artisan::call)

        $products = Product::with(['variants' => fn ($q) => $q->orderBy('id'), 'category', 'brand'])->orderBy('id')->get();

        if (! $dryRun) {
            $this->writeBackup($products);
        }

        DB::beginTransaction();

        try {
            $this->fixProductNames($products);
            $this->fixSlugs($products);
            $this->fixMissingBrands($products);

            foreach ($products as $product) {
                $this->removeLeftoverVariants($product);
                $this->fixVariantConfigs($product);
                $this->fixForeignSkus($product);
                $this->fixVariantTitles($product);
                $this->checkPrices($product);
            }

            $this->markFeatured();

            if ($this->option('with-skus')) {
                $this->generateSkus();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->report($dryRun);

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- products

    private function fixProductNames(Collection $products): void
    {
        foreach ($products as $product) {
            $clean = $this->stripDeal($product->name);

            if ($clean !== $product->name) {
                $this->change("Product #{$product->id} name", $product->name, $clean);
                $product->update(['name' => $clean]);
            }
        }
    }

    /**
     * slug = Str::slug(name). Done in two steps so products can swap slugs
     * (Corsair currently holds "macbook-air-m3", which MacBook Air M3 needs).
     */
    private function fixSlugs(Collection $products): void
    {
        $changing = $products->filter(fn ($p) => Str::slug($p->name) !== $p->slug);

        foreach ($changing as $product) {
            DB::table('products')->where('id', $product->id)->update(['slug' => "tmp-fix-{$product->id}"]);
        }

        foreach ($changing as $product) {
            $base = Str::slug($product->name);
            $slug = $base;
            for ($n = 2; DB::table('products')->where('slug', $slug)->where('id', '<>', $product->id)->exists(); $n++) {
                $slug = "{$base}-{$n}";
            }

            $this->change("Product #{$product->id} slug", $product->slug, $slug);
            // Query builder (not $product->update) so the temporary slug is always replaced
            DB::table('products')->where('id', $product->id)->update(['slug' => $slug, 'updated_at' => now()]);
            $product->slug = $slug;
            $product->syncOriginal();
        }
    }

    /** "Dell XPS 15" with no brand -> brand "Dell", when a brand with that first word exists. */
    private function fixMissingBrands(Collection $products): void
    {
        foreach ($products->whereNull('brand_id') as $product) {
            $brand = Brand::where('slug', Brand::slugFor(Str::before($product->name, ' ')))->first();

            if ($brand) {
                $this->change("Product #{$product->id} brand ({$product->name})", '(none)', $brand->name);
                $product->update(['brand_id' => $brand->id]);
            } else {
                $this->decide("Product #{$product->id} \"{$product->name}\" has no brand. Set it in Admin > Products.");
            }
        }
    }

    private function markFeatured(): void
    {
        $slugs = array_filter(array_map('trim', explode(',', (string) $this->option('feature'))));

        foreach ($slugs as $slug) {
            $product = Product::where('slug', $slug)->first();

            if (! $product) {
                $this->decide("--feature: no product with slug \"{$slug}\" (use the new slugs listed above).");
            } elseif (! $product->is_featured) {
                $this->change("Product #{$product->id} featured ({$product->name})", 'no', 'yes');
                $product->update(['is_featured' => true]);
            }
        }
    }

    // ---------------------------------------------------------------- variants

    /**
     * A variant whose title starts with another model's name AND whose option keys share
     * nothing with its sibling variants was left over when the product was renamed
     * (e.g. "HP Spectre x360 14" · 16GB 512GB" inside the LG monitor).
     */
    private function removeLeftoverVariants(Product $product): void
    {
        foreach ($product->variants as $variant) {
            $siblingKeys = $product->variants->where('id', '<>', $variant->id)
                ->flatMap(fn ($v) => array_map([$this, 'normalizeKey'], array_keys($v->config ?? [])))
                ->unique();

            $ownKeys = collect(array_keys($variant->config ?? []))->map([$this, 'normalizeKey']);

            $foreignTitle = ! Str::startsWith($this->stripDeal($variant->title), $product->name);
            $foreignKeys  = $siblingKeys->isNotEmpty() && $ownKeys->intersect($siblingKeys)->isEmpty();

            if ($foreignTitle && $foreignKeys) {
                $this->change("Variant #{$variant->id} DELETED (in \"{$product->name}\")", $variant->title, '—');
                $variant->delete();
            }
        }

        $product->load(['variants' => fn ($q) => $q->orderBy('id')]);
    }

    private function fixVariantConfigs(Product $product): void
    {
        $isGpu = $product->category?->slug === 'graphic-cards';

        foreach ($product->variants as $variant) {
            $config = [];

            foreach ($variant->config ?? [] as $key => $value) {
                $key   = $this->normalizeKey($key);
                $value = is_string($value) ? trim($value) : $value;

                // {"12GB": "OC"} -> the key was typed where the value should be
                if (preg_match('/^\d+\s*(gb|tb)$/i', $key) && ! isset($config['edition'])) {
                    $key = 'edition';
                }

                // Units: "256" -> "256GB", "1T" -> "1TB"
                if (in_array($key, ['ram', 'storage', 'vram'], true) && is_string($value)) {
                    $value = preg_replace('/^(\d+)\s*$/', '$1GB', $value);
                    $value = preg_replace('/^(\d+)\s*T$/i', '$1TB', $value);
                }

                $config[$key] = $value;
            }

            if ($isGpu) {
                unset($config['storage']);                       // a graphics card has no storage
                if (Str::contains($product->name, 'RTX 4090')) {
                    $config['ram'] = '24GB';                     // every RTX 4090 has 24GB
                }
            }

            if ($config !== ($variant->config ?? [])) {
                $this->change("Variant #{$variant->id} options", $this->json($variant->config), $this->json($config));
                $variant->update(['config' => $config]);
            }
        }
    }

    private function fixForeignSkus(Product $product): void
    {
        foreach ($product->variants as $variant) {
            foreach (self::SKU_OWNERS as $prefix => $owner) {
                if ($variant->sku && Str::startsWith($variant->sku, $prefix) && ! Str::startsWith($product->name, $owner)) {
                    $this->change("Variant #{$variant->id} SKU (belongs to {$owner})", $variant->sku, '(empty)');
                    $variant->update(['sku' => null]);
                }
            }
        }
    }

    /**
     * Rebuild "Model · options" like the admin form does. When two variants of one product
     * would get the same title (their options are identical), they are left alone and
     * reported, because a person has to decide which one is right.
     */
    private function fixVariantTitles(Product $product): void
    {
        $visible  = $product->variants->where('is_active', true);
        $proposed = $visible->mapWithKeys(fn ($v) => [
            $v->id => $this->variants->makeTitle($product->name, $v->config ?? []),
        ]);

        $clashing = $proposed->duplicates();

        foreach ($visible as $variant) {
            $new = $proposed[$variant->id];

            if ($clashing->contains($new)) {
                // Keep the person's title, only drop the "🔥 Deal" mark
                $new = $this->stripDeal($variant->title);
                $others = $visible->filter(fn ($v) => $proposed[$v->id] === $proposed[$variant->id])->pluck('id')->implode(', #');
                $this->decide("Variants #{$others} of \"{$product->name}\" have the same options ({$this->json($variant->config)}). "
                    . 'Fix the options, or hide/delete one in Admin > Products > Variants.');
            }

            if ($new !== $variant->title) {
                $this->change("Variant #{$variant->id} title", $variant->title, $new);
                $variant->update(['title' => $new]);
            }
        }
    }

    private function checkPrices(Product $product): void
    {
        $product->variants->where('is_active', true)->groupBy(fn ($v) => (string) $v->price)
            ->filter(fn ($group) => $group->count() > 1 && $group->pluck('config')->unique()->count() > 1)
            ->each(fn ($group) => $this->decide(
                "Variants #" . $group->pluck('id')->implode(', #') . " of \"{$product->name}\" have different options but the same price \${$group->first()->price}. Check the prices."
            ));
    }

    private function generateSkus(): void
    {
        ProductVariant::whereNull('sku')->orderBy('id')->each(function (ProductVariant $variant) {
            $sku = sprintf('GMX-%03d-%04d', $variant->product_id, $variant->id);
            $this->change("Variant #{$variant->id} SKU", '(empty)', $sku);
            $variant->update(['sku' => $sku]);
        });
    }

    // ---------------------------------------------------------------- helpers

    /** "Chip:" -> "chip", "RAM" -> "ram", "Gpu Wattage" -> "gpu_wattage" */
    public function normalizeKey(string $key): string
    {
        return Str::of($key)->trim()->rtrim(':')->trim()->lower()->replaceMatches('/\s+/', '_')->value();
    }

    private function stripDeal(string $text): string
    {
        return trim(preg_replace('/\s{2,}/', ' ', preg_replace(self::DEAL_MARK, ' ', $text)));
    }

    private function json(?array $value): string
    {
        return json_encode($value ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function change(string $what, ?string $from, ?string $to): void
    {
        $this->changes[] = [$what, (string) $from, (string) $to];
    }

    private function decide(string $message): void
    {
        $this->decisions[$message] = $message;
    }

    private function writeBackup(Collection $products): void
    {
        $path = 'catalog-fix-backups/' . now()->format('Y-m-d_His') . '.json';

        Storage::disk('local')->put($path, json_encode(
            $products->map(fn ($p) => [
                'product'  => $p->only(['id', 'name', 'slug', 'brand_id', 'is_featured']),
                'variants' => $p->variants->map->only(['id', 'title', 'config', 'sku', 'price', 'stock_quantity'])->values(),
            ])->values(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->info('Backup of the current data: ' . Storage::disk('local')->path($path));
    }

    private function report(bool $dryRun): void
    {
        $this->newLine();

        if ($this->changes === []) {
            $this->info('Nothing to fix: the catalog is already clean.');
        } else {
            $this->table(['What', 'Before', 'After'], $this->changes);
            $this->info(($dryRun ? 'DRY RUN — nothing was saved. ' : 'Saved. ') . count($this->changes) . ' change(s).');
            if ($dryRun) {
                $this->line('Run again without --dry-run to apply.');
            }
        }

        if ($this->decisions !== []) {
            $this->newLine();
            $this->warn('Needs your decision (not changed):');
            foreach ($this->decisions as $message) {
                $this->line("  • {$message}");
            }
        }
    }
}
