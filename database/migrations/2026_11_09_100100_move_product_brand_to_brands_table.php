<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * products.brand (free text) -> brands table + products.brand_id
 *
 * Existing values are copied into `brands`. Product-line names that were typed
 * as a brand are mapped to the real manufacturer (MacBook -> Apple, ThinkPad -> Lenovo).
 * Values that differ only by case ("asus" / "ASUS") become one brand.
 */
return new class extends Migration
{
    /** slug of the old value => real brand name */
    private const ALIASES = [
        'macbook'  => 'Apple',
        'mac'      => 'Apple',
        'apple'    => 'Apple',
        'thinkpad' => 'Lenovo',
        'lenovo'   => 'Lenovo',
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('series')
                ->constrained('brands')->restrictOnDelete();
        });

        $now = now();

        $oldValues = DB::table('products')
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->distinct()
            ->pluck('brand');

        foreach ($oldValues as $old) {
            $name = self::ALIASES[Str::slug($old)] ?? trim($old);
            $slug = Str::slug($name) ?: Str::lower($name);

            $brandId = DB::table('brands')->where('slug', $slug)->value('id')
                ?? DB::table('brands')->insertGetId([
                    'name'       => $name,
                    'slug'       => $slug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('products')->where('brand', $old)->update(['brand_id' => $brandId]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['brand']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->index()->after('series');
        });

        foreach (DB::table('brands')->get(['id', 'name']) as $brand) {
            DB::table('products')->where('brand_id', $brand->id)->update(['brand' => $brand->name]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
