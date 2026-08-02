# Security

## Secrets

Keep credentials only in the server `.env` file. Never commit:

- Slack webhook URLs or bot tokens
- Database, mail, or administrator passwords
- API keys, private keys, or production environment files

The committed `.env.example` files contain blank or example values only.
Rotate any credential that has appeared in chat, screenshots, logs, or Git
history.

## Accounts

Each customer signs up for their own workspace (organization). Inside a
workspace, a Super Admin or authorized Admin creates accounts from the Users
page. Use passwords of at least 12 characters.

Provision a workspace and its owner with:

```bash
php artisan tenant:create "Acme Co" owner@acme.test
```

Operator/landlord access to the platform portal is a separate model and guard —
grant it with `php artisan platform:grant`, never with a customer super admin.

## Tenant isolation (the primary security boundary)

Every tenant-owned model carries `organization_id` and a global
`OrganizationScope`. The scope deliberately emits **no** predicate when no
tenant is bound, because login, password reset, `TenantProvisioner` and console
commands all legitimately run unbound — making the scope itself fail closed
would break authentication.

Fail-closed is therefore enforced at the request boundary by
`App\Http\Middleware\EnsureTenantBound` (alias `tenant.required`), applied to the
whole `web` and `api` middleware groups. It refuses, logs and signs out any
authenticated customer whose `organization_id` is NULL, and refuses any request
where the bound tenant disagrees with the authenticated user.

Rules that follow from this:

- **Never** create a user without an organization. `user:create-admin` requires
  `--org=<id|slug>`; `AdminUserSeeder` requires `ADMIN_ORG`. Both stamp the
  tenant through `CurrentOrganization::runFor()` — never hardcode
  `organization_id`.
- Bulk writes (`insert()`, `upsert()`) bypass Eloquent events, so
  `BelongsToOrganization` does not stamp them. Pass `organization_id` explicitly
  or the rows land NULL and fall outside every tenant's scope.
- The project ships no database-level foreign keys (SQLite + MySQL
  portability), so the invariant is enforced in the application. Verify it in CI
  and after every deploy:

```bash
php artisan tenancy:audit          # fails if any user has no organization
php artisan tenancy:audit --strict # also fails on orphaned rows in other tables
```

## Production

- Use HTTPS.
- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Point the web root to `public`, not the repository root.
- Restrict `.env` permissions to the server account.
- Keep Laravel and npm dependencies patched.
- Back up the database and `storage/app/public`.

Run the checks in `.github/PRE-COMMIT-CHECKLIST.md` before deployment.
