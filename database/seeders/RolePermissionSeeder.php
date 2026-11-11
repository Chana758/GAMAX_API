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

    /** Orders are placed by customers and never deleted: staff can only view and update them. */
    private const ORDER_PERMISSIONS = ['order.view', 'order.update'];

    /** Money: staff record what they receive; only admins give money back. */
    private const PAYMENT_PERMISSIONS = ['payment.record', 'payment.refund'];
    private const ADMIN_ONLY = ['payment.refund'];

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
        foreach ([...self::ORDER_PERMISSIONS, ...self::PAYMENT_PERMISSIONS] as $name) {
            $all[] = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->name;
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        // Admin: everything. Staff: everything except delete and refunds.
        $admin->givePermissionTo($all);
        $staff->givePermissionTo(array_filter(
            $all,
            fn ($p) => ! str_ends_with($p, '.delete') && ! in_array($p, self::ADMIN_ONLY, true)
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
