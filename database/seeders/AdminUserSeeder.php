<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEPRECATED for new installs — see CreateAdminUser. Staffvora is multi-tenant:
 * `php artisan tenant:create` provisions organization + owner together.
 *
 * This seeder now REQUIRES ADMIN_ORG (an organization id or slug) and refuses to
 * run without it. Seeding a user with organization_id = NULL used to produce an
 * account that authenticates normally and then runs every tenant query with no
 * organization predicate — unrestricted cross-customer read and write.
 *
 * Usage: ADMIN_ORG=acme php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        $orgKey = env('ADMIN_ORG');

        if (blank($email) || blank($password)) {
            $this->command?->warn('Admin user not created: set ADMIN_EMAIL and ADMIN_PASSWORD first.');
            $this->command?->line('Prefer php artisan tenant:create "Acme" owner@acme.com for a new workspace.');

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->command?->error('Admin user not created: use a valid email and a password of at least 12 characters.');

            return;
        }

        if (blank($orgKey)) {
            $this->command?->error('Admin user not created: ADMIN_ORG (organization id or slug) is required.');
            $this->command?->line('Every user must belong to a workspace — a null organization is a cross-tenant hole.');

            return;
        }

        $orgKey = trim((string) $orgKey);

        $organization = Organization::query()
            ->when(ctype_digit($orgKey), fn ($q) => $q->where('id', (int) $orgKey), fn ($q) => $q->where('slug', $orgKey))
            ->first();

        if (! $organization) {
            $this->command?->error("Admin user not created: no organization matches '{$orgKey}'.");

            return;
        }

        // Logins are global, so uniqueness must be checked across every tenant.
        if (User::withoutOrganizationScope()->where('email', $email)->exists()) {
            $this->command?->info('Admin user already exists. Skipping...');

            return;
        }

        // runFor() binds the tenant so BelongsToOrganization stamps organization_id.
        $user = app(CurrentOrganization::class)->runFor($organization, fn () => User::create([
            'name' => env('ADMIN_NAME', 'Admin'),
            'email' => $email,
            'role' => 'super_admin',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ]));

        if (! $user->organization_id) {
            $user->forceDelete();
            $this->command?->error('Admin user not created: organization stamping failed.');

            return;
        }

        $this->command?->info("Admin user created successfully in {$organization->name} (#{$organization->id}).");
    }
}
