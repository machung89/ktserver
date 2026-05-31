<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'phone' => '0900000000',
                'password' => Hash::make('password'),
                'remember_token' => Str::random(10),
                'email_verified_at' => now(),
            ]
        );

        $this->call([SystemAccountsSeeder::class, AccountSeeder::class, RolePermissionSeeder::class, BankSeeder::class, ProvinceSeeder::class]);
    }
}
