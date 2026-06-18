<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create-admin {--name=} {--email=} {--password=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Super Admin user interactively or with options';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Creating a new admin user...');
        $this->newLine();

        // Get user details
        $name = $this->option('name') ?: $this->ask('Admin name');
        $email = $this->option('email') ?: $this->ask('Admin email');

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error('  - '.$error);
            }

            return 1;
        }

        $password = $this->option('password') ?: $this->secret('Admin password');

        if (! $password || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return 1;
        }

        // Confirm creation
        if (! $this->confirm("Create admin user '$name' with email '$email'?", true)) {
            $this->warn('Operation cancelled.');

            return 0;
        }

        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'role' => 'super_admin',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
            ]);

            $this->newLine();
            $this->info('Admin user created successfully!');
            $this->table(
                ['ID', 'Name', 'Email', 'Role'],
                [[$user->id, $user->name, $user->email, $user->role]]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to create admin user: '.$e->getMessage());

            return 1;
        }
    }
}
