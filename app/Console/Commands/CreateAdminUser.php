<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * DEPRECATED for new installs. Staffvora is multi-tenant: a workspace owner is
 * created together with its organization by `php artisan tenant:create`, and
 * operator/landlord access lives in the separate PlatformUser model
 * (`php artisan platform:grant`).
 *
 * This command survives only to add an extra super admin to an EXISTING
 * organization, and it now REQUIRES `--org=` for exactly that reason: a user row
 * with organization_id = NULL authenticates normally and then runs every tenant
 * query with no organization predicate, i.e. unrestricted cross-customer
 * read/write. It must never be possible to create one.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'user:create-admin {--name=} {--email=} {--password=} {--org= : Organization id or slug the admin belongs to (required)}';

    protected $description = '[Deprecated — prefer tenant:create] Add a super admin to an EXISTING organization';

    public function handle(CurrentOrganization $current)
    {
        $this->warn('Deprecated: use `php artisan tenant:create` for a new workspace,');
        $this->warn('and `php artisan platform:grant` for operator/landlord access.');
        $this->newLine();

        $organization = $this->resolveOrganization();

        if (! $organization) {
            return 1;
        }

        $this->info("Creating a new admin user in workspace: {$organization->name} (#{$organization->id})");
        $this->newLine();

        $name = $this->option('name') ?: $this->ask('Admin name');
        $email = $this->option('email') ?: $this->ask('Admin email');

        // Email uniqueness is global (the login form has no tenant selector), so
        // check across every tenant, not just the bound one.
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error('  - '.$error);
            }

            return 1;
        }

        $taken = User::withoutOrganizationScope()->where('email', $email)->exists();

        if ($taken) {
            $this->error('Validation failed:');
            $this->error('  - A user with that email already exists.');

            return 1;
        }

        $password = $this->option('password') ?: $this->secret('Admin password');

        if (! $password || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return 1;
        }

        if (! $this->confirm("Create admin user '$name' with email '$email' in '{$organization->name}'?", true)) {
            $this->warn('Operation cancelled.');

            return 0;
        }

        try {
            // runFor() binds the tenant so BelongsToOrganization stamps
            // organization_id — never hardcode or pass it by hand.
            $user = $current->runFor($organization, fn () => User::create([
                'name' => $name,
                'email' => $email,
                'role' => 'super_admin',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
            ]));

            if (! $user->organization_id) {
                $this->error('Refusing to keep a user with no organization. Rolling back.');
                $user->forceDelete();

                return 1;
            }

            $this->newLine();
            $this->info('Admin user created successfully!');
            $this->table(
                ['ID', 'Name', 'Email', 'Role', 'Organization'],
                [[$user->id, $user->name, $user->email, $user->role, $organization->slug]]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to create admin user: '.$e->getMessage());

            return 1;
        }
    }

    private function resolveOrganization(): ?Organization
    {
        $key = $this->option('org') ?: $this->ask('Organization id or slug');
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            $this->error('--org is required. Every user must belong to a workspace.');
            $this->line('List them with: php artisan tenancy:audit');

            return null;
        }

        $organization = Organization::query()
            ->when(ctype_digit($key), fn ($q) => $q->where('id', (int) $key), fn ($q) => $q->where('slug', $key))
            ->first();

        if (! $organization) {
            $this->error("No organization matches '{$key}'.");

            return null;
        }

        return $organization;
    }
}
