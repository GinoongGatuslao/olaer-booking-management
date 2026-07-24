# Deployment, Scheduler, Backup, and Restore Guide

This guide describes the expected operational setup. Adjust paths, credentials, domains, and server commands to the actual hosting environment.

## Required production services

```text
PHP version supported by Laravel 13
Composer
Node.js and npm
MySQL or compatible production database
Web server such as Nginx or Apache
Process capable of running Laravel scheduler
Writable storage and cache directories
HTTPS certificate
```

## Production environment

Never commit the production `.env`.

Recommended production values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.example

LOG_CHANNEL=stack
LOG_LEVEL=warning

SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Configure the actual database values securely:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=olaer_booking
DB_USERNAME=restricted_application_user
DB_PASSWORD=strong-secret
```

Configure no-show processing:

```dotenv
OLAER_NO_SHOW_RELEASE_TIME=03:00
OLAER_NO_SHOW_RELEASE_TIMEZONE=Asia/Manila
OLAER_NO_SHOW_RELEASE_LIMIT=100
```

## First deployment

From the application directory:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan key:generate
php artisan migrate --force
php artisan storage:link

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Create staff accounts and configuration data using the approved production process. Do not seed demonstration passwords into production.

## File permissions

The web-server user must be able to write to:

```text
storage/
bootstrap/cache/
```

Do not make the entire repository world-writable.

## Scheduler

Laravel's scheduler must run every minute.

Linux cron example:

```cron
* * * * * cd /var/www/olaer-booking-management && php artisan schedule:run >> /dev/null 2>&1
```

Confirm registration:

```bash
php artisan schedule:list
```

Confirm that the no-show command appears:

```text
olaer:release-no-show-reservations
```

Manual controlled test:

```bash
php artisan olaer:release-no-show-reservations --date=2026-08-02
```

Use a test database or carefully prepared records when running with an explicit past date.

## Queue

When the application uses queued notifications or mail:

```bash
php artisan queue:work --tries=3 --timeout=90
```

Use a process manager such as Supervisor or systemd in production.

## Database backup

### MySQL command-line backup

```bash
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --default-character-set=utf8mb4 \
  -u BACKUP_USER \
  -p \
  olaer_booking \
  > olaer_booking_YYYY-MM-DD_HHMM.sql
```

Store backups outside the public web directory.

Protect backup files because they contain guest, staff, and financial data.

### Application-file backup

Back up at minimum:

```text
.env
storage/app/
storage/app/public/
private payment-proof storage
custom deployment configuration
```

Do not rely on Git for uploaded payment proofs or generated operational files.

## Backup schedule

Recommended minimum:

```text
Database: daily
Uploaded/private files: daily
Full server snapshot: weekly
Off-server copy: daily or after each backup
Retention: define with project adviser or resort management
```

A practical retention example:

```text
7 daily backups
4 weekly backups
6 monthly backups
```

This is an operational example, not a legal retention requirement.

## Restore procedure

1. Put the application in maintenance mode:

```bash
php artisan down
```

2. Back up the current broken or incomplete state before replacing it.

3. Restore the database:

```bash
mysql -u RESTORE_USER -p olaer_booking < olaer_booking_BACKUP.sql
```

4. Restore uploaded/private files to their original storage paths.

5. Confirm `.env` and permissions.

6. Clear and rebuild caches:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

7. Run migrations only when restoring into a newer application version:

```bash
php artisan migrate --force
```

8. Verify:

```bash
php artisan about
php artisan migrate:status
php artisan schedule:list
php artisan test
```

9. Return the application online:

```bash
php artisan up
```

## Post-deployment verification

Verify these workflows:

```text
staff login
role-based access
reservation availability
payment recording
GCash proof access
check-in
amenity request handoff
inspection request handoff
checkout blocking
final payment
successful checkout
report generation
activity log creation
no-show scheduler registration
```

## Security checklist

```text
APP_DEBUG is false
HTTPS is enabled
production .env is not public
database user has only required privileges
payment proofs are not publicly enumerable
passwords are hashed
activity logs redact sensitive values
backups are encrypted or access-controlled
default demonstration accounts are removed
server and dependencies are patched
```

## Rollback

Before deploying a new version:

```text
1. Record the current Git commit.
2. Back up database and uploaded files.
3. Deploy the new application version.
4. Run migrations.
5. perform smoke tests.
```

When rollback is required:

```text
1. Enter maintenance mode.
2. Restore the previous application commit.
3. Restore the matching database backup when migrations were destructive or incompatible.
4. Restore matching uploaded files where necessary.
5. Rebuild caches.
6. Run smoke tests.
7. Return online.
```

Never roll back application code without considering database schema compatibility.
