<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class CategoryService
{
    // Get all categories
    public function getAll(): Collection
    {
        return Category::ordered()->get();
    }

    // Find category by slug
    public function findBySlug(string $slug): Category
    {
        return Category::where('slug', $slug)->firstOrFail();
    }

    // Get paginated categories for admin
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $query = Category::query()->ordered();

        // Search categories by name (case-insensitive on PostgreSQL too)
        if (!empty($filters['search'])) {
            $like = '%' . addcslashes(mb_strtolower($filters['search']), '%_\\') . '%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        // Return paginated results (max 100 per page)
        return $query->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    // Create a new category
    public function create(array $data): Category
    {
        $data['slug'] = $this->resolveSlug(
            $data['slug'] ?? null,
            $data['name']
        );

        return Category::create($data);
    }

    // Update an existing category
    public function update(Category $category, array $data): Category
    {
        // Generate slug when name or slug changes
        if (array_key_exists('slug', $data) || array_key_exists('name', $data)) {
            $data['slug'] = $this->resolveSlug(
                $data['slug'] ?? null,
                $data['name'] ?? $category->name,
                $category->id
            );
        }

        $category->update($data);

        return $category->fresh();
    }

    // Delete a category
    public function delete(Category $category): void
    {
        $category->delete();
    }

    // Generate a unique slug
    private function resolveSlug(
        ?string $slug,
        string $name,
        ?int $ignoreId = null
    ): string {
        // Create slug from slug or name
        $base = Str::slug($slug ?: $name);

        $candidate = $base;
        $number = 2;

        // Add number if slug already exists
        while (
            Category::where('slug', $candidate)
                ->when(
                    $ignoreId,
                    fn ($query) => $query->where('id', '!=', $ignoreId)
                )
                ->exists()
        ) {
            $candidate = "{$base}-{$number}";
            $number++;
        }

        return $candidate;
    }
}