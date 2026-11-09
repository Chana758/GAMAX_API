<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProductVariant;
use Illuminate\Validation\Validator;

/**
 * Two visible variants of the same product may not have the same options or the same title
 * (the customer could not tell them apart). Hidden variants (is_active = false) are ignored.
 */
trait ChecksDuplicateVariants
{
    protected function checkDuplicates(Validator $validator, int $productId, ?ProductVariant $current = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $willBeActive = $this->has('is_active') ? $this->boolean('is_active') : ($current?->is_active ?? true);
        if (! $willBeActive) {
            return;
        }

        $siblings = ProductVariant::query()
            ->where('product_id', $productId)
            ->active()
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->get(['id', 'title', 'config']);

        $config = $this->has('config') ? $this->input('config') : $current?->config;
        $canonical = ProductVariant::canonicalConfig($config);
        $sameOptions = $siblings->first(fn ($v) => ProductVariant::canonicalConfig($v->config) === $canonical);

        if ($sameOptions && ($this->has('config') || $this->has('is_active'))) {
            $validator->errors()->add('config', "Variant #{$sameOptions->id} \"{$sameOptions->title}\" already has these options. Change an option, or hide that variant first.");
        }

        $title = trim((string) $this->input('title', ''));
        if ($title !== '' && $siblings->contains(fn ($v) => mb_strtolower($v->title) === mb_strtolower($title))) {
            $validator->errors()->add('title', 'Another visible variant of this product already has this title.');
        }
    }
}
