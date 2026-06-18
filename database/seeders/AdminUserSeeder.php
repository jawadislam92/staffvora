<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * Usage: php artisan db:seed --class=AdminUserSeeder
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command->warn('Admin user not created: set ADMIN_EMAIL and ADMIN_PASSWORD first.');
            $this->command->line('You can also run php artisan user:create-admin interactively.');

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->command->error('Admin user not created: use a valid email and a password of at least 12 characters.');

            return;
        }

        // Check if admin already exists
        $adminExists = User::where('email', $email)->exists();

        if ($adminExists) {
            $this->command->info('Admin user already exists. Skipping...');

            return;
        }

        User::create([
            'name' => env('ADMIN_NAME', 'Admin'),
            'email' => $email,
            'role' => 'super_admin',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ]);

        $this->command->info('Admin user created successfully!');
    }
}
