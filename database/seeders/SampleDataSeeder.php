<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Project;
use App\Models\Datasource;
use App\Models\Canvas;
use App\Models\ProjectAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SampleDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create sample project
        $user = User::where('email', 'test@example.com')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        if ($user) {
            // Create sample project
            $project = Project::create([
                'id_user' => $user->id_user,
                'name' => 'Sample BI Project',
                'description' => 'This is a sample Business Intelligence project for testing purposes.',
                'created_by' => $user->email,
                'modified_by' => $user->email,
                'created_time' => now(),
                'modified_time' => now(),
                'is_deleted' => false,
            ]);

            // Create sample datasource
            $datasource = Datasource::create([
                'id_project' => $project->id_project,
                'name' => 'Sample PostgreSQL DB',
                'type' => 'pgsql',
                'host' => 'localhost',
                'port' => 5432,
                'database_name' => 'sample_db',
                'username' => 'postgres',
                'password' => 'password',
                'created_by' => $user->email,
                'modified_by' => $user->email,
                'created_time' => now(),
                'modified_time' => now(),
                'is_deleted' => false,
            ]);

            // Create sample canvas
            $canvas = Canvas::create([
                'id_project' => $project->id_project,
                'name' => 'Main Dashboard',
                'created_by' => $user->email,
                'modified_by' => $user->email,
                'created_time' => now(),
                'modified_time' => now(),
                'is_deleted' => false,
            ]);

            // Give admin access to the project
            if ($admin) {
                ProjectAccess::create([
                    'id_project' => $project->id_project,
                    'id_user' => $admin->id_user,
                    'access' => 'admin',
                    'created_by' => $user->email,
                    'modified_by' => $user->email,
                    'created_time' => now(),
                    'modified_time' => now(),
                    'is_deleted' => false,
                ]);
            }
        }
    }
}
