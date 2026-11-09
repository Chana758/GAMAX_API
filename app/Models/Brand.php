<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'sort_order',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        // LOWER(): "be quiet!" sorts with the B's, not after "Z"
        return $query->orderBy('sort_order')->orderByRaw('LOWER(name)');
    }

    /** "ASUS " -> "asus". Falls back to lowercase for names Str::slug() cannot transliterate. */
    public static function slugFor(string $name): string
    {
        $name = trim($name);

        return Str::slug($name) ?: Str::lower($name);
    }

    /** Find a brand by name (case-insensitive) or create it. */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);

        return static::firstOrCreate(['slug' => static::slugFor($name)], ['name' => $name]);
    }
}
