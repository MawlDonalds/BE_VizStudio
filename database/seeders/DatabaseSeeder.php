<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user directly without factory
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'created_by' => 'test@example.com',
            'modified_by' => 'test@example.com',
            'created_time' => now(),
            'modified_time' => now(),
            'is_deleted' => false,
        ]);

        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'created_by' => 'admin@example.com',
            'modified_by' => 'admin@example.com',
            'created_time' => now(),
            'modified_time' => now(),
            'is_deleted' => false,
        ]);

        // Call other seeders
        $this->call([
            SampleDataSeeder::class,
        ]);
    }
}
