# Olaer Spring Resort Booking & Billing System

Laravel 13 / Livewire 4 application for Olaer Spring Resort operations: multi-facility reservations and bookings, automatic physical facility assignment, cashiering, GCash verification, entrance slips, amenities, inspection/fines, billing statements, reporting, and auditable staff workflows.

## Current authoritative behavior

The implementation follows `OLAER_MASTER_IMPLEMENTATION_HANDOFF.md` and the Batch 0 audit in `docs/implementation/BATCH_0_BASELINE_AUDIT.md`.

Core rules:

- One Reservation and one Booking may contain many facility details.
- Guests and Cashiers select facility product/type, schedule, quantity, and estimated users; exact physical facility numbers are assigned automatically.
- Schedule ownership uses database-backed facility schedule blocks and collision protection.
- Historical committed prices use immutable snapshots; current master-rate edits must not reprice old transactions.
- New financial math uses `DecimalMoneyService`.
- Reservations require at least 50% of the final discounted total in verified payment. Public GCash submissions remain Pending until Cashier verification.
- Automatic discounts require an active, dated validity period and do not stack; the best monetary discount applies.
- Rooms include four guests, allow up to ten, and require every occupant name to be assigned to the exact room.
- Individual facility cancellation does not cancel the parent transaction. Eligible paid value stays as same-transaction credit only.
- Facility transfers are upgrade-only; cheaper/downgrade transfers are rejected.
- Direct Walk-In Booking requires full facility and admission payment before admission. Optional amenity charges may remain outstanding.
- Amenity delivery does not require advance payment; delivered amenity charges may remain outstanding on the active Booking.
- Inspection fines remain draft until Complete Inspection; publication, booking charge, request completion, and locking occur atomically.
- Locked Entrance Slips are corrected by voiding the old slip and creating a replacement; locked history is never edited in place.
- Facility creation uses Clone Facility; Facility Number is never copied from the source.
- Manager is not an application role. Administrative responsibilities belong to Admin.
- Tourist backend data remains for historical compatibility but approved UI presentation is hidden.

## Roles

- Admin
- Cashier
- Maintenance Staff
- Security Guard
- Public Guest

Demo staff accounts are seeded for local/demo environments:

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `password` |
| Cashier | `cashier` | `password` |
| Maintenance Staff | `maintenance` | `password` |
| Security Guard | `security` | `password` |

Do not use demo credentials in production.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm run build
php artisan test
```

For development, run Laravel/Herd as appropriate and `npm run dev`.

## Storage

Laravel Cloud uses:

- `storage-4-public` for public application assets where configured.
- `storage-4-private` for protected GCash proofs.

GCash proofs must remain private and be served only through authorized application access.

## CI

`.github/workflows/laravel-ci.yml` runs:

- PHP 8.4 dependency checks;
- Node frontend build;
- fresh migrations;
- route/schedule verification;
- the full test suite on SQLite; and
- an additional MySQL 8.4 migration + full regression job.

Do not treat SQLite-only success as sufficient for migration/concurrency confidence.

## Documentation

- `docs/PROJECT_ARCHITECTURE_AND_WORKFLOW.md`
- `docs/Olaer_Spring_Resort_Comprehensive_User_Manual.md`
- `docs/USER_ACCEPTANCE_TEST_CHECKLIST.md`
- `docs/DEFENSE_DEMO_GUIDE.md`
- `docs/CONTINUOUS_INTEGRATION_AND_RELEASE_CHECKS.md`
- `docs/DEPLOYMENT_BACKUP_AND_RESTORE.md`
- `docs/implementation/BATCH_0_BASELINE_AUDIT.md`

## Branch policy for the current implementation cycle

All implementation work for this cycle is on `UX-updates`. `main` is not to be modified until the owner explicitly chooses to merge after validation.
