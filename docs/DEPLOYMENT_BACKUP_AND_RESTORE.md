# Deployment, Backup and Restore

## Laravel Cloud deployment checklist

Validate the exact release commit before promotion.

### Environment

- production `APP_ENV`, `APP_KEY`, `APP_URL`;
- database credentials and MySQL version;
- queue connection/worker;
- scheduler;
- SMTP/email;
- application log/monitoring configuration;
- upload/post size compatible with proof files.

### Buckets

- public bucket: `storage-4-public`;
- private bucket: `storage-4-private`.

GCash proof objects must never be made public. Verify authorized read, unauthorized denial, new upload, legacy proof compatibility and deletion/cleanup behavior.

### Database

Before deployment:

1. take a restorable database backup;
2. run migration preflight;
3. ensure no unresolved Manager accounts remain—the Manager-removal migration deliberately stops instead of silently reassigning;
4. ensure legacy active reservation/booking details can be represented by schedule blocks without collision;
5. run upgrade migrations against a copy of realistic production data where possible;
6. run the full MySQL 8.4 suite.

### Functional smoke test

After deployment validate:

- public Reservation;
- public direct Booking;
- Cashier multi-facility Reservation/Booking;
- Walk-In facility + admission payment and amenity outstanding balance;
- GCash private upload/read + verify/reject;
- reservation conversion;
- per-detail reschedule/upgrade/cancel/credit;
- entrance slip pre-admission edit and locked void/replacement;
- amenity request lifecycle;
- inspection draft fine → Complete Inspection publish;
- detailed billing statement and print;
- role navigation and logout/login/Remember Me;
- email delivery.

### Backup

Back up both relational data and private/public object storage references. Record backup time and release commit. A database-only backup is insufficient if referenced private proof objects cannot be restored.

### Restore drill

A restore is valid only when a clean environment can restore the database and object storage, boot the app, authorize private proof access correctly, and reconcile representative billing/schedule records.
