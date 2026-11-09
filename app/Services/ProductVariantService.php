<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

class ProductVariantService
{
    public function listForProduct(Product $product): Collection
    {
        return $product->variants()->latest('id')->get();
    }

    public function create(Product $product, array $data): ProductVariant
    {
        // No title sent -> generate one from the product name + config values
        if (empty($data['title'])) {
            $data['title'] = $this->makeTitle($product->name, $data['config'] ?? []);
        }
        $data = $this->syncStock($data);
        return $product->variants()->create($data);
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $titleSent = array_key_exists('title', $data);
        $titleBlank = empty($data['title']);

        // Regenerate the title when:
        //  - the title was sent but blank (explicit "please regenerate"), or
        //  - the config changed and no title was sent.
        // The title column is NOT NULL, so it must never be saved as null.
        if ($titleBlank && ($titleSent || array_key_exists('config', $data))) {
            $config = $data['config'] ?? $variant->config ?? [];
            $data['title'] = $this->makeTitle($variant->product->name, $config);
        }
        $data = $this->syncStock($data);
        $variant->update($data);

        return $variant->fresh();
    }

    public function delete(ProductVariant $variant): void
    {
        $variant->delete();
    }

    /**
     * Build "Model · config values", skipping values already contained in the model name.
     */
    private function makeTitle(string $model, array $config): string
    {
        $extra = collect($config)
            ->filter(fn ($v) => is_scalar($v) && ! str_contains($model, (string) $v))
            ->implode(' ');

        return $extra === '' ? $model : "{$model} · {$extra}";
    }

    private function syncStock(array $data): array
    {
        if (array_key_exists('stock_quantity', $data)) {
            $data['in_stock'] = $data['stock_quantity'] > 0;
        }
        return $data;
    }
}