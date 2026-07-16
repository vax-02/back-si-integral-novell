<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\UserRoles;

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
       
        $user = User::updateOrCreate(
            [
                'email' => 'admin@example.com',
            ],
            [
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
        UserRoles::create([
            'user_id' => $user->id,
            'role_id' => 1
        ]);
    }
}
