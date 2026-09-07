<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure admin role exists in Spatie (so Edit User form role dropdown shows correctly)
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web']
        );

        $email = 'admin@etaxi.com';
        $password = 'password';

        // Create default admin user for first login.
        $admin = User::updateOrCreate(
            ['phone' => '9876543210', 'role_id' => 1],
            [
                'name' => 'Admin User',
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
            ]
        );

        // Assign Spatie role so the admin appears correctly on Edit User (role dropdown)
        if (! $admin->hasRole($adminRole)) {
            $admin->assignRole($adminRole);
        }

        // Generate all resource permissions and assign them to the admin role
        $this->call(PermissionSeeder::class);

        $this->command->info('Admin user seeded successfully!');
        $this->command->info('Admin login: '.$email.' / '.$password.'');
    }
}
