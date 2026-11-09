<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Last line of defence when orders start changing stock: the database itself
 * refuses negative stock, negative prices and an old price that is not higher.
 * (Validation already checks this; these rules also stop bugs and manual SQL.)
 *
 * SQLite (used by the tests) cannot add constraints to an existing table, so it is skipped there.
 */
return new class extends Migration
{
    private const CHECKS = [
        'product_variants_stock_quantity_check' => 'stock_quantity >= 0',
        'product_variants_price_check'          => 'price >= 0',
        'product_variants_compare_price_check'  => 'compare_price IS NULL OR compare_price > price',
    ];

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        $bad = DB::table('product_variants')
            ->where('stock_quantity', '<', 0)
            ->orWhere('price', '<', 0)
            ->orWhere(fn ($q) => $q->whereNotNull('compare_price')->whereColumn('compare_price', '<=', 'price'))
            ->pluck('id');

        if ($bad->isNotEmpty()) {
            throw new RuntimeException(
                'Fix these variants first (negative stock/price, or compare price not higher than price): #'
                . $bad->implode(', #') . '. Then run php artisan migrate again.'
            );
        }

        foreach (self::CHECKS as $name => $rule) {
            DB::statement("ALTER TABLE product_variants ADD CONSTRAINT {$name} CHECK ({$rule})");
        }
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }

        $drop = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT IF EXISTS';

        foreach (array_keys(self::CHECKS) as $name) {
            DB::statement("ALTER TABLE product_variants {$drop} {$name}");
        }
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true);
    }
};
