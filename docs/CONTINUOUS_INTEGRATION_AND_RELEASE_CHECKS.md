# Continuous Integration and Release Checks

## Required CI gate

The workflow `.github/workflows/laravel-ci.yml` must be green at the exact release commit.

### Standard job

- PHP 8.4
- Composer install
- Node install/build
- fresh migration
- route verification
- schedule verification
- full Laravel test suite

### MySQL 8.4 job

A separate `mysql:8.4` service runs:

```bash
php artisan migrate:fresh --force --no-interaction
php artisan test
```

This is mandatory because availability locking and migration behavior must not be accepted from SQLite-only confidence.

## Release blockers

Do not release if any of these are unexplained:

- migration failure;
- schedule-block collision or ambiguous legacy backfill;
- failing financial snapshot/ledger regression;
- pending GCash counted as settled;
- multi-facility availability mismatch;
- downgrade transfer accepted;
- cross-transaction credit allocation;
- draft inspection fine visible as Cashier liability;
- completed inspection mutable through normal workflow;
- unauthorized private proof access;
- Manager assignable or routed;
- stale Facility Preset creation exposed to users.

## Branch

Current implementation work is performed on `UX-updates`. `main` must remain untouched until the project owner explicitly merges a validated commit.
