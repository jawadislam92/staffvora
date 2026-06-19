<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Voluntarily import an existing roster into a tenant — the onboarding path for a
 * customer moving onto the product (including Sparking Asia, if they ever choose
 * to). The internal SA Track keeps running as-is; nothing is forced.
 *
 * Imports USERS and CLIENTS from CSV. Idempotent on email: a user that already
 * exists (anywhere) is skipped and reported, never duplicated or overwritten.
 *
 * Users CSV headers:   name,email[,role,designation,joining_date,shift_start_time,team]
 * Clients CSV headers: name[,work_type,tags]   (tags = comma-separated)
 */
class TenantImport extends Command
{
    protected $signature = 'tenant:import
        {org : Organization id or slug to import into}
        {--users= : Path to a users CSV}
        {--clients= : Path to a clients CSV}
        {--default-password= : Password for imported users (generated + printed if omitted)}
        {--dry-run : Parse and validate only; write nothing}';

    protected $description = 'Import an existing users/clients roster into a tenant from CSV (voluntary migration).';

    public function handle(CurrentOrganization $current): int
    {
        $org = $this->resolveOrg($this->argument('org'));
        if (! $org) {
            $this->error('Organization not found: '.$this->argument('org'));

            return self::FAILURE;
        }

        if (! $this->option('users') && ! $this->option('clients')) {
            $this->error('Nothing to do — pass --users=<file> and/or --clients=<file>.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $password = $this->option('default-password') ?: Str::password(16);

        $this->info(($dry ? '[dry-run] ' : '')."Importing into: {$org->name} (#{$org->id})");

        // Run inside the tenant context so created rows are stamped + scoped.
        $summary = $current->runFor($org, function () use ($dry, $password) {
            return [
                'users' => $this->option('users') ? $this->importUsers($this->option('users'), $password, $dry) : null,
                'clients' => $this->option('clients') ? $this->importClients($this->option('clients'), $dry) : null,
            ];
        });

        if (($summary['users']['created'] ?? 0) > 0 && ! $dry) {
            $this->newLine();
            $this->warn("Imported users share the password: {$password}");
            $this->line('Tell them to reset it on first sign-in.');
        }

        $rows = [];
        foreach (['users', 'clients'] as $kind) {
            if ($summary[$kind] !== null) {
                $s = $summary[$kind];
                $rows[] = [ucfirst($kind), $s['created'], $s['skipped'], implode('; ', array_slice($s['notes'], 0, 5))];
            }
        }
        $this->table(['Type', 'Created', 'Skipped', 'Notes (first 5)'], $rows);

        return self::SUCCESS;
    }

    private function resolveOrg(string $key): ?Organization
    {
        return is_numeric($key)
            ? Organization::find((int) $key)
            : Organization::where('slug', $key)->first();
    }

    /** @return array{created:int, skipped:int, notes:array<int,string>} */
    private function importUsers(string $path, string $password, bool $dry): array
    {
        $created = 0;
        $skipped = 0;
        $notes = [];
        $hashed = Hash::make($password);

        foreach ($this->readCsv($path) as $line => $row) {
            $name = trim($row['name'] ?? '');
            $email = strtolower(trim($row['email'] ?? ''));
            if ($name === '' || $email === '') {
                $skipped++;
                $notes[] = "row {$line}: missing name/email";

                continue;
            }
            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                $skipped++;
                $notes[] = "{$email}: already exists";

                continue;
            }

            $role = in_array($row['role'] ?? '', ['member', 'admin', 'super_admin'], true) ? $row['role'] : 'member';
            $teamId = null;
            if (! empty($row['team'])) {
                $team = $dry ? null : Team::firstOrCreate(['name' => trim($row['team'])]);
                $teamId = $team?->id;
            }

            if (! $dry) {
                User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $hashed,
                    'role' => $role,
                    'designation' => $row['designation'] ?? null,
                    'joining_date' => ! empty($row['joining_date']) ? $row['joining_date'] : null,
                    'shift_start_time' => ! empty($row['shift_start_time']) ? $row['shift_start_time'] : null,
                    'team_id' => $teamId,
                ]);
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    /** @return array{created:int, skipped:int, notes:array<int,string>} */
    private function importClients(string $path, bool $dry): array
    {
        $created = 0;
        $skipped = 0;
        $notes = [];

        foreach ($this->readCsv($path) as $line => $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $skipped++;
                $notes[] = "row {$line}: missing name";

                continue;
            }
            if (Client::where('name', $name)->exists()) {
                $skipped++;
                $notes[] = "{$name}: already exists";

                continue;
            }

            if (! $dry) {
                Client::create([
                    'name' => $name,
                    'work_type' => $row['work_type'] ?? null,
                    'tags' => ! empty($row['tags']) ? array_map('trim', explode(',', $row['tags'])) : null,
                ]);
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * Read a CSV into header-keyed rows. Throws a clear error if unreadable.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read CSV: {$path}");

            return [];
        }

        $rows = [];
        $handle = fopen($path, 'r');
        $header = null;
        $line = 0;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim($h)), $cols);

                continue;
            }
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            $rows[$line] = array_combine($header, array_pad(array_slice($cols, 0, count($header)), count($header), null));
        }
        fclose($handle);

        return $rows;
    }
}
