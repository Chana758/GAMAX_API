<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two visible variants of one product may not share a title
 * (in the cart the customer could not tell them apart).
 * Hidden variants (is_active = false) are ignored, so an old variant can be
 * hidden and a new one with the same name created.
 *
 * Existing duplicates: an out-of-stock copy is hidden automatically (nothing is
 * deleted — switch it back on in Admin > Products > Variants). Duplicates that are
 * both in stock stop the migration with a message, because a person must choose.
 */
return new class extends Migration
{
    private const INDEX = 'product_variants_product_id_title_active_unique';

    public function up(): void
    {
        foreach ($this->duplicateGroups() as $group) {
            $inStock = $group->filter(fn ($v) => $v->stock_quantity > 0);
            $hide    = $inStock->isNotEmpty() ? $group->diffKeys($inStock) : $group->slice(1);

            foreach ($hide as $variant) {
                DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => false]);
                echo "  Hid duplicate out-of-stock variant #{$variant->id} \"{$variant->title}\"\n";
            }
        }

        $left = $this->duplicateGroups();
        if ($left->isNotEmpty()) {
            throw new RuntimeException(
                'These variants have the same title and are all in stock: '
                . $left->map(fn ($g) => '#' . $g->pluck('id')->implode('/#') . ' "' . $g->first()->title . '"')->implode('; ')
                . '. Rename, hide or delete one of each in Admin > Products > Variants, then run php artisan migrate again.'
            );
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // MySQL has no partial indexes: unique for all variants
            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON product_variants (product_id, title)');
        } else {
            DB::statement('CREATE UNIQUE INDEX ' . self::INDEX . ' ON product_variants (product_id, title) WHERE is_active = true');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX ' . self::INDEX . ' ON product_variants');
        } else {
            DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
        }
    }

    /** Active variants grouped by (product, title), only groups with more than one. */
    private function duplicateGroups()
    {
        return DB::table('product_variants')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'product_id', 'title', 'stock_quantity'])
            ->groupBy(fn ($v) => $v->product_id . '|' . $v->title)
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->keyBy('id'))
            ->values();
    }
};
