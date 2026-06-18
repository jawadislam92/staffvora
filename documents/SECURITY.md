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

Public registration is disabled. A Super Admin or authorized Admin creates
accounts from the Users page. Use passwords of at least 12 characters.

Create the first Super Admin with:

```bash
php artisan user:create-admin
```

## Production

- Use HTTPS.
- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Point the web root to `public`, not the repository root.
- Restrict `.env` permissions to the server account.
- Keep Laravel and npm dependencies patched.
- Back up the database and `storage/app/public`.

Run the checks in `.github/PRE-COMMIT-CHECKLIST.md` before deployment.
