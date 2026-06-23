<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@logique.co.id'],
            [
                'name' => 'Admin Logique',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'arianto@logique.co.id'],
            [
                'name' => 'Arianto',
                'password' => Hash::make('password'),
                'role' => 'pm',
                'is_active' => true,
            ]
        );
    }
}
