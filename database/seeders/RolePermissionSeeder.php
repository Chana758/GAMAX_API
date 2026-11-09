<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Safe to run again at any time (php artisan db:seed --class=RolePermissionSeeder):
 * it only adds missing roles/permissions and never removes ones given by hand.
 */
class RolePermissionSeeder extends Seeder
{
    /** Resources checked by the Policies and the upload route ("product.view", ...). */
    private const RESOURCES = ['category', 'brand', 'product', 'variant', 'banner'];

    private const ACTIONS = ['view', 'create', 'update', 'delete'];

    public function run(): void
    {
        // Clear Spatie's cached roles/permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = [];
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $all[] = Permission::firstOrCreate(
                    ['name' => "{$resource}.{$action}", 'guard_name' => 'web']
                )->name;
            }
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        // Admin: everything. Staff: everything except delete.
        $admin->givePermissionTo($all);
        $staff->givePermissionTo(array_filter($all, fn ($p) => ! str_ends_with($p, '.delete')));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
