<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('variant.view');
    }

    public function view(User $user, ProductVariant $productVariant): bool
    {
        return $user->can('variant.view');
    }

    public function create(User $user): bool
    {
        return $user->can('variant.create');
    }

    public function update(User $user, ProductVariant $productVariant): bool
    {
        return $user->can('variant.update');
    }

    public function delete(User $user, ProductVariant $productVariant): bool
    {
        // Needs its own permission; add "variant.delete" to RolePermissionSeeder.
        return $user->can('variant.delete');
    }

    // Variants do not use soft deletes, so restore/forceDelete are always denied.
    public function restore(User $user, ProductVariant $productVariant): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductVariant $productVariant): bool
    {
        return false;
    }
}