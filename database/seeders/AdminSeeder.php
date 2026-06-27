<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin Utama',
            'email' => 'admin@oleochemical.pro',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Supervisor',
            'email' => 'supervisor@oleochemical.pro',
            'password' => Hash::make('password123'),
            'role' => 'supervisor',
        ]);
    }
}
