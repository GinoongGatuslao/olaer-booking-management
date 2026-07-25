# Continuous Integration and Release Checks

## Purpose

The GitHub Actions workflow provides an automated quality gate for every push
to `main`, every pull request targeting `main`, and manual workflow runs.

The workflow does not deploy the application. It verifies that the repository
can be installed, built, migrated, routed, scheduled, and tested.

## Workflow file

```text
.github/workflows/laravel-ci.yml
```

## Checks performed

```text
1. Check out the repository
2. Install PHP 8.3 and required SQLite extensions
3. Install Composer dependencies
4. Create the Laravel environment and application key
5. Install Node.js 22 dependencies with npm ci
6. Build production frontend assets
7. Run all migrations against a temporary SQLite database
8. Verify the Laravel route registry
9. Verify the Laravel scheduler registry
10. Run the complete Laravel test suite
```

## Triggers

```text
Push to main
Pull request targeting main
Manual workflow dispatch
```

## Security posture

The workflow uses:

```yaml
permissions:
  contents: read
```

It does not use repository secrets and does not use the more privileged
`pull_request_target` event.

## Concurrency

A newer run for the same branch or pull request cancels an older unfinished
run. This avoids wasting CI minutes on superseded commits.

## Local equivalent

Run these commands from the project root before pushing:

```bash
composer install
php artisan optimize:clear

npm ci
npm run build

php artisan migrate:status
php artisan route:list
php artisan schedule:list
php artisan test
```

For a fresh local SQLite migration check in PowerShell:

```powershell
New-Item -ItemType File -Force database\ci.sqlite | Out-Null
$env:DB_CONNECTION = "sqlite"
$env:DB_DATABASE = (Resolve-Path database\ci.sqlite).Path
php artisan migrate:fresh --force
Remove-Item Env:DB_CONNECTION
Remove-Item Env:DB_DATABASE
```

Do not run `migrate:fresh` against a production or development database that
contains records you need.

## Reading a failed workflow

### Composer installation failure

Review the PHP version, required extensions, lock-file consistency, and package
compatibility.

### Frontend build failure

Review `package-lock.json`, Node.js compatibility, Vite build errors, and
missing frontend imports.

### Migration failure

Review migration order, foreign-key names, SQLite compatibility, and duplicate
or missing columns.

### Route or scheduler failure

Review invalid route definitions, missing controllers or components, duplicate
route names, and scheduled-command registration.

### Test failure

Use the first failing test, exception, file, and line as the correction target.
Do not weaken a valid business-rule test merely to make CI green.

## Merge readiness

```text
Build and test job is green
Focused tests for the changed workflow pass locally
Full php artisan test passes
Migration and route checks pass
No production secrets are committed
```
