# Pre-Commit Checklist

Run these checks before pushing:

```powershell
git status
git diff --check
git diff --cached
git ls-files .env .env.production
composer validate --strict
composer audit --locked
npm audit --omit=dev
php artisan test
npm run build
```

- Confirm `.env`, Slack webhook URLs, tokens, passwords, and production
  credentials are not staged.
- Confirm database migrations work on both a fresh database and the existing
  production database.
- Rotate any credential that has appeared in chat, screenshots, logs, or Git
  history.
- Review the staged file list before committing.
