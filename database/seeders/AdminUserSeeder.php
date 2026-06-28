<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
        ]);

        User::updateOrCreate(
            [
                'email' => 'admin@example.com',
            ],
            [
                'role_id' => $adminRole->id,
                'ci' => '00000001',
                'name' => 'Admin',
                'first_lastname' => 'System',
                'second_lastname' => null,
                'email' => 'admin@example.com',
                'password' => Hash::make('123456789'),
                'cellphone' => null,
                'status' => 1,
            ]
        );
    }
}
