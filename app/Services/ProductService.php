<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductService
{
    /** Relations every product response needs (brand name, gallery). */
    private const DETAIL_RELATIONS = ['category', 'brand', 'images'];

    // ======================
    // Part 1: Admin Products
    // ======================

    public function paginateForAdmin(array $filter = [], int $perPage = 15): LengthAwarePaginator
    {
        return Product::query()
            // images: the edit form needs the current gallery, otherwise saving would wipe it
            ->with(['category', 'brand', 'images'])
            ->withCount('variants')
            ->when(
                $filter['category_id'] ?? null,
                fn (Builder $q, $id) => $q->where('category_id', $id)
            )
            ->when(
                $filter['search'] ?? null,
                fn (Builder $q, $search) => $this->whereSearch($q, $search)
            )
            ->when(
                $filter['brand'] ?? null,
                fn (Builder $q, $brand) => $this->whereBrand($q, $brand)
            )
            ->when(
                filter_var($filter['on_sale'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $this->whereOnSale($q)
            )
            ->when(
                array_key_exists('is_active', $filter) && $filter['is_active'] !== null && $filter['is_active'] !== '',
                fn (Builder $q) => $q->where(
                    'is_active',
                    filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN)
                )
            )
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): Product
    {
        $gallery = Arr::pull($data, 'images');
        $data    = $this->resolveBrand($data);

        $product = DB::transaction(function () use ($data, $gallery) {
            $product = Product::create($data);

            if ($gallery !== null) {
                $this->syncGallery($product, $gallery);
            }

            return $product;
        });

        return $product->fresh(self::DETAIL_RELATIONS);
    }

    public function update(Product $product, array $data): Product
    {
        $gallery  = Arr::pull($data, 'images');
        $data     = $this->resolveBrand($data);
        $oldCover = $product->image;
        $removed  = [];

        DB::transaction(function () use ($product, $data, $gallery, &$removed) {
            $product->update($data);

            if ($gallery !== null) {
                $removed = $this->syncGallery($product, $gallery);
            }
        });

        // Delete old files only AFTER the DB update succeeded,
        // so a failed update never leaves the product pointing at a missing file.
        if (array_key_exists('image', $data) && $data['image'] !== $oldCover) {
            $removed[] = $oldCover;
        }
        $this->deleteImages($removed, $product->fresh('images'));

        return $product->fresh(self::DETAIL_RELATIONS);
    }

    public function delete(Product $product): void
    {
        $files = $product->images()->pluck('path')->push($product->image)->all();

        $product->delete(); // product_images rows are removed by cascadeOnDelete

        $this->deleteImages($files);
    }

    /**
     * brand_id wins when both are sent. A brand name is matched case-insensitively
     * ("asus" = "ASUS") and created when it does not exist yet. Empty name = no brand.
     */
    private function resolveBrand(array $data): array
    {
        if (! array_key_exists('brand', $data)) {
            return $data;
        }

        $name = trim((string) Arr::pull($data, 'brand'));

        if (! array_key_exists('brand_id', $data)) {
            $data['brand_id'] = $name === '' ? null : Brand::findOrCreateByName($name)->id;
        }

        return $data;
    }

    /**
     * Replace the gallery with $paths (in the given order).
     * Returns the paths that are no longer used by this product's gallery.
     */
    private function syncGallery(Product $product, array $paths): array
    {
        $paths    = array_values(array_unique($paths));
        $previous = $product->images()->pluck('path')->all();

        $product->images()->delete();

        foreach ($paths as $index => $path) {
            $product->images()->create(['path' => $path, 'sort_order' => $index]);
        }

        return array_values(array_diff($previous, $paths));
    }

    /**
     * Only deletes files under /storage/ (never touches the seeder's /images/ files),
     * and never a file the product still uses as cover or gallery image.
     */
    private function deleteImages(array $paths, ?Product $product = null): void
    {
        $stillUsed = $product
            ? $product->images->pluck('path')->push($product->image)->filter()->all()
            : [];

        foreach (array_unique(array_filter($paths)) as $path) {
            if (! in_array($path, $stillUsed, true) && Str::startsWith($path, '/storage/')) {
                Storage::disk('public')->delete(Str::after($path, '/storage/'));
            }
        }
    }

    // ========================
    // Part 2: Public Products
    // ========================

    /**
     * ?category=laptop&search=rtx&brand=asus,msi&on_sale=1&featured=1
     * &min_price=500&max_price=1000&sort=newest|price_asc|price_desc|featured
     */
    public function paginatePublic(array $filter = [], int $perPage = 12): LengthAwarePaginator
    {
        $min = $filter['min_price'] ?? null;
        $max = $filter['max_price'] ?? null;

        $query = Product::query()
            ->where('is_active', true)
            ->with(['category', 'brand', 'images', 'variants'])
            ->when(
                $filter['category'] ?? null,
                fn (Builder $q, $slug) => $q->whereHas(
                    'category',
                    fn ($c) => $c->where('slug', $slug)
                )
            )
            ->when(
                $filter['search'] ?? null,
                fn (Builder $q, $search) => $this->whereSearch($q, $search)
            )
            ->when(
                $filter['brand'] ?? null,
                fn (Builder $q, $brand) => $this->whereBrand($q, $brand)
            )
            ->when(
                filter_var($filter['on_sale'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $this->whereOnSale($q)
            )
            ->when(
                filter_var($filter['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $q->where('is_featured', true)
            )
            ->when(
                is_numeric($min) || is_numeric($max),
                fn (Builder $q) => $q->whereHas('variants', function ($v) use ($min, $max) {
                    if (is_numeric($min)) {
                        $v->where('price', '>=', $min);
                    }
                    if (is_numeric($max)) {
                        $v->where('price', '<=', $max);
                    }
                })
            );

        // Sort by the cheapest variant price of each product
        $minPriceSub = fn () => ProductVariant::query()
            ->selectRaw('MIN(price)')
            ->whereColumn('product_id', 'products.id');

        match ($filter['sort'] ?? null) {
            'price_asc'  => $query->orderBy($minPriceSub(), 'asc')->orderByDesc('id'),
            'price_desc' => $query->orderBy($minPriceSub(), 'desc')->orderByDesc('id'),
            'featured'   => $query->orderByDesc('is_featured')->latest('id'),
            default      => $query->latest('id'), // newest
        };

        return $query->paginate($perPage);
    }

    /** Route: products/{product:slug} */
    public function findPublicBySlug(Product $product): Product
    {
        abort_unless($product->is_active, 404);

        return $product->load([...self::DETAIL_RELATIONS, 'variants']);
    }

    /** Admin product detail */
    public function loadForAdmin(Product $product): Product
    {
        return $product->load([...self::DETAIL_RELATIONS, 'variants'])->loadCount('variants');
    }

    // ===============
    // Query helpers
    // ===============

    /**
     * Case-insensitive "contains" search on name, series and brand name.
     * Works on PostgreSQL, MySQL and SQLite (ILIKE is PostgreSQL-only).
     * LIKE wildcards typed by the user are escaped.
     */
    private function whereSearch(Builder $query, string $search): Builder
    {
        $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('LOWER(products.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(products.series) LIKE ?', [$like])
                ->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(brands.name) LIKE ?', [$like]));
        });
    }

    /** ?brand=asus or ?brand=ASUS,MSI or ?brand[]=asus (names or slugs) */
    private function whereBrand(Builder $query, string|array $brand): Builder
    {
        $slugs = collect(is_array($brand) ? $brand : explode(',', $brand))
            ->map(fn ($b) => is_scalar($b) ? trim((string) $b) : '')
            ->filter()
            ->map(fn ($b) => Brand::slugFor($b))
            ->unique()
            ->values()
            ->all();

        return $slugs === []
            ? $query
            : $query->whereHas('brand', fn ($b) => $b->whereIn('slug', $slugs));
    }

    /** At least one variant is cheaper than its compare (old) price. */
    private function whereOnSale(Builder $query): Builder
    {
        return $query->whereHas(
            'variants',
            fn ($v) => $v->whereNotNull('compare_price')->whereColumn('compare_price', '>', 'price')
        );
    }
}
