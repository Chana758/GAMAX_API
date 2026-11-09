<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin account
        $admin = User::firstOrCreate(
            ['email' => 'admin@gammax.com'],
            [
                'name'     => 'GAMMAX Admin',
                'phone'    => '011111111',
                'password' => Hash::make('Password123'),
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');

        // Staff account (for test)
        $staff = User::firstOrCreate(
            ['email' => 'staff@gammax.com'],
            [
                'name'     => 'Test Staff',
                'phone'    => '013333333',
                'password' => Hash::make('Password123'),
                'is_active' => true,
            ]
        );
        $staff->assignRole('staff');

        // Customer account (for test login/register flow)
        $customer = User::firstOrCreate(
            ['email' => 'customer@gammax.com'],
            [
                'name'     => 'Test Customer',
                'phone'    => '012222222',
                'password' => Hash::make('Password123'),
                'is_active' => true,
            ]
        );
        $customer->assignRole('customer');
    }
}