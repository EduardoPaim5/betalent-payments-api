<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@betalent.local',
                'role' => UserRole::ADMIN,
            ],
            [
                'name' => 'Manager User',
                'email' => 'manager@betalent.local',
                'role' => UserRole::MANAGER,
            ],
            [
                'name' => 'Finance User',
                'email' => 'finance@betalent.local',
                'role' => UserRole::FINANCE,
            ],
            [
                'name' => 'Common User',
                'email' => 'user@betalent.local',
                'role' => UserRole::USER,
            ],
        ];

        foreach ($users as $data) {
            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password123'),
                    'role' => $data['role'],
                ],
            );
        }
    }
}
