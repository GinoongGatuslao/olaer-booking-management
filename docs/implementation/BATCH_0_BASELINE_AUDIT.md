# Olaer Batch 0 Baseline Audit

**Date:** 2026-09-24  
**Authoritative plan:** `OLAER_MASTER_IMPLEMENTATION_HANDOFF.md` supplied by project owner  
**Repository:** `GinoongGatuslao/olaer-booking-management`  
**Audited branch:** `UX-updates`  
**Baseline SHA:** `74e66c0d9108eac473b842364c7c81edc5b3d5fd`  
**Main/UX comparison at audit start:** identical (0 commits ahead / 0 behind)

## Guardrails

- All implementation work must stay on `UX-updates`; do not write to `main`.
- Preserve accepted normalized FacilityProduct/ProductRate, financial snapshots, DecimalMoneyService, schedule blocks, facility requirement/assignment services, private GCash proof storage, and pre-admission entrance-slip editing.
- Do not perform a broad Flux removal. The historical Flux constraint is recorded as conflicting legacy guidance only.
- Historical committed financial values must remain snapshot-based and immutable against master-data edits.
- Availability/financial mutations must remain transaction- and lock-safe.

## Execution environment / CI

### Confirmed

- `.github/workflows/laravel-ci.yml` runs on pushes to both `main` and `UX-updates`.
- CI uses PHP 8.4, Node 22, frontend build, SQLite `migrate:fresh`, route/schedule verification, and the full Laravel test suite.
- Current baseline SHA has no visible workflow runs or combined commit statuses.

### Constraint found

The connected GitHub execution surface can inspect and modify the repository but does not provide a shell checkout/runtime. A direct container clone was attempted but outbound DNS/network access to github.com is unavailable. Therefore local PHPUnit/MySQL execution cannot be represented as completed from this environment.

The first audit commit on `UX-updates` intentionally triggers the existing GitHub Actions workflow so the repository's own CI can become the executable regression gate. MySQL 8.4 upgrade/concurrency confidence remains a separate Batch 0/14 requirement because current CI is SQLite-only.

## Requirement audit matrix

| Area | Status | Evidence / conclusion |
|---|---|---|
| Branch safety | COMPLIANT | `main` and `UX-updates` confirmed identical at audit start. Work target is explicitly `UX-updates`. |
| Normalized facility products/rates | COMPLIANT / PRESERVE | `FacilityProduct`, `ProductRate`, canonical rate/schedule policies and related services exist. |
| Financial snapshots | COMPLIANT / PRESERVE | Existing detail-assignment flow writes quote/detail snapshots rather than deriving committed prices from mutable current rates. |
| Decimal money | COMPLIANT / PRESERVE | Existing `DecimalMoneyService` is part of the accepted foundation and is already consumed by assignment/financial flows. |
| Schedule blocks | COMPLIANT FOUNDATION / BACKFILL NOT PROVEN | `FacilityScheduleBlockService` and schedule-block ownership/collision protection exist and are used by current assignment flows. Deterministic legacy backfill for all pre-existing active reservation/booking details was not proven in repository search. |
| Facility planner foundation | COMPLIANT FOUNDATION | `FacilityRequirementIntent`, `FacilityRequirementGroup`, `FacilityRequirementService`, `FacilityAssignmentService`, availability and planning tests exist. |
| Standalone planner route | MISSING FINAL INTEGRATION | `/plan-facilities` remains in `routes/web.php`. It must become an embedded/shared step rather than the final primary UX. |
| Guest reservation/booking multi-facility | PARTIAL | Multi-facility reservation creation capability exists in `FacilityAssignmentService::createReservation()`, but public guest/cashier entry flows still contain legacy paths and have not all been migrated onto the shared planner. |
| Cashier reservation/booking shared planner | PARTIAL / MISSING | Legacy cashier workflows remain and must be integrated with the same requirement/availability/assignment engine. |
| Manager role removal | MISSING | `Manager` is present in routes, GCash/print access, login routing, seeders, middleware/layout context, navigation, settings and documentation. |
| Facility Preset -> Clone Facility | MISSING | `FacilityManagementService::createFromPreset()`, Admin facility create UI and preset tests remain. |
| Numeric min/max capacity | MISSING | Existing legacy capacity/facility-size presentation remains; no final `min_capacity`/`max_capacity` migration was proven. |
| Tourist UI hiding | MISSING / PARTIAL | Tourist remains referenced by current entrance/security/report presentation. Backend data should remain. |
| Amenity/Fine Usage presentation removal | MISSING | Usage summary/sorting/count presentation remains in admin modules. |
| Raw confirm replacement | MISSING / PARTIAL | Raw `wire:confirm` remains in identified workflows. |
| Approved navigation | MISSING | Existing Staff Navigation still uses older role grouping; Maintenance/Security dashboard structure remains. |
| Billing history | PARTIAL | Current billing is booking-centric; final guest-centric history/statement scope is not yet present. |
| Inspection fine draft/publish | CONFLICTING / HIGH PRIORITY | `FacilityInspectionWorkflowService::recordFine()` is the old immediate-posting model. Final required draft -> atomic Complete Inspection -> publish/lock model is not implemented. |
| Entrance-slip pre-admission edit | COMPLIANT / PRESERVE | Existing workflow includes pre-admission editing foundation. |
| Entrance-slip void/cancel+recreate after lock | MISSING / NOT PROVEN | No dedicated final locked-slip correction lifecycle was proven. |
| Remember Me | COMPLIANT CODE / TEST MISSING | Livewire login binds `remember` and passes it as the second argument to `Auth::attempt()`; regression coverage for remembered vs normal login is missing. |
| Direct Walk-In Booking | MISSING / NOT PROVEN | Final direct walk-in rules (active/checked-in, core 100% admission gate, amenity balance allowed) are not proven. |
| Same-transaction cancellation credit ledger | MISSING | No authoritative transaction-scoped credit/allocation ledger implementation was proven. |
| Required markers / row highlight | PARTIAL / NOT PROVEN | No consistent repository-wide implementation was proven from search. Requires module-by-module UI pass. |
| Fixed top-right operational notifications | PARTIAL / NOT PROVEN | No consistent global implementation was proven. |
| Activity Log detail/User-Agent modal | MISSING / NOT PROVEN | No User-Agent/detail modal implementation was located by search. |
| Print Back closes print tab with fallback | MISSING / NOT PROVEN | No `window.close`/fallback behavior was located. |
| Responsive global polish | PARTIAL | Existing responsive styling exists, but handoff-required repository-wide consistency is not proven. |
| Private GCash proof storage | COMPLIANT / PRESERVE | Existing private Cloud storage/access hardening exists and must remain private. |
| Documentation | STALE | README/manual/architecture/demo documentation still includes Manager/Preset/old behavior and must be updated last. |
| Flux historical constraint | CONFLICTING LEGACY GUIDANCE | Current app is Flux-based. No broad framework rewrite will be started without explicit owner direction. |

## Batch 0 acceptance findings

### Safe to proceed

The repository has the hardened domain foundations anticipated by the handoff. The main implementation path should be migration/integration rather than a rebuild:

1. preserve shared planner/assignment/schedule/financial foundations;
2. add migration-safe legacy schema support and new ledger/draft-fine structures;
3. move legacy guest/cashier flows to the shared facility-planning engine;
4. finish role/master-data/UI cleanup after core transactional correctness.

### Blocking risks to handle before broad UI work

1. Manager removal must be migration-safe and must not silently discard/reassign real Manager accounts.
2. Legacy schedule-block coverage must be audited/backfilled deterministically; collisions must stop migration rather than silently select a winner.
3. New credit/adjustment and draft-inspection-fine schemas must be established before dependent financial/UI behavior.
4. Inspection fines must stop changing booking liability until Complete Inspection.
5. MySQL 8.4 migration/concurrency coverage is not provided by the current SQLite-only CI and needs an explicit executable test path.

## Batch 0 implementation decision

Proceed to Batch 1 in the handoff order. Do not begin a broad presentation/navigation rewrite before migration and financial invariants are established.

## CI gate

This audit document is committed only to `UX-updates`. Because the existing workflow runs on `UX-updates` pushes, this commit is also used to establish the first observable CI baseline for the takeover.
