# Olaer Final Acceptance Status

This document is the post-implementation acceptance map for the authoritative Olaer implementation handoff. It distinguishes repository/CI evidence from deployment checks that can only be proven in the real Laravel Cloud environment.

## Automated repository gates

The following are enforced by implementation and/or regression tests on both SQLite and MySQL 8.4 CI:

- safe Manager-role removal with an explicit stop when legacy Manager accounts still require reassignment;
- numeric facility capacity migration and approved product/rate catalog;
- canonical schedule blocks and overlap prevention;
- shared multi-facility planning and automatic physical-unit assignment;
- immutable facility pricing snapshots;
- reservation-to-booking conversion with schedule ownership transfer;
- named room occupants preserved through conversion;
- 50% minimum reservation GCash verification and remaining-balance preservation;
- Pending GCash does not count as settled;
- duplicate/reference and private-proof access controls;
- dated automatic facility discounts, no undated auto-apply, and highest valid discount selection;
- individual reservation-detail isolation;
- upgrade/extension schedule and financial safeguards;
- same-transaction-only transaction credits;
- Walk-In core facility/admission full-payment gate;
- Walk-In amenity-at-creation with unpaid amenity balance allowed;
- Entrance Slip exact-payment, lock, and void/recreate behavior;
- inspection draft fines do not affect the booking until atomic completion/publish;
- Clone Facility behavior without copying the source facility number;
- Remember Me regression;
- approved Security Guard home flow through Entrance Slip instead of a redundant dashboard;
- Philippine application timezone default;
- Composer metadata/lock validation;
- frontend asset build, route registration, scheduler registration, fresh migrations, and full feature regression.

## CI matrix

The Laravel CI workflow runs:

1. PHP 8.4 + Node 22 + SQLite:
   - Composer metadata/lock validation;
   - Composer install;
   - frontend install/build;
   - fresh migrations;
   - route listing;
   - scheduler listing;
   - full Laravel test suite.
2. PHP 8.4 + Node 22 + MySQL 8.4:
   - Composer metadata/lock validation;
   - Composer install;
   - frontend install/build;
   - fresh MySQL migration;
   - full Laravel test suite.

A branch is not considered release-ready when either job is red.

## Deployment-only gates

These require the actual Laravel Cloud environment and cannot be conclusively proven by repository CI alone:

- production MySQL credentials and migration execution against the deployment database;
- attached Laravel Cloud buckets named `storage-4-private` and `storage-4-public`;
- real GCash proof upload, authorized read, and delete against the private bucket;
- queue worker process health;
- scheduler process/cron health in the deployed environment;
- SMTP credentials and successful real notification/email delivery;
- PHP/web-server upload and POST limits sufficient for the application GCash proof limit of 4 MiB;
- production `APP_URL`, HTTPS and session/cookie behavior;
- desktop/tablet/mobile browser review of the final UI;
- print/back behavior in actual browser tabs;
- backup creation, restore into a clean target, and post-restore smoke test;
- production log/monitoring review.

## Required production environment baseline

At minimum verify:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` set to the deployed HTTPS URL
- `APP_TIMEZONE=Asia/Manila`
- production `APP_KEY`
- MySQL production connection
- database-backed session/cache/queue configuration as deployed
- working SMTP configuration
- attached `storage-4-private` and `storage-4-public` buckets
- queue worker enabled
- scheduler enabled

## Release rule

Do not mark the production deployment accepted solely because GitHub Actions is green. Repository acceptance requires both CI jobs green on the exact release head. Production acceptance additionally requires the deployment-only gates above to be executed and recorded.
