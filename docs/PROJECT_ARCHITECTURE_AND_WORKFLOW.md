# Project Architecture and Workflow

## Architecture

The application is a Laravel 13 server-rendered system using Livewire 4 / Volt, Blade, Tailwind CSS and the existing Flux UI layer. Business correctness lives in services and database constraints; Livewire components orchestrate those services rather than duplicating pricing or availability logic.

### Facility domain

`FacilityProduct` / `ProductRate` define reusable normalized products and canonical rates. `Facility` represents a physical unit with a unique Facility Number and numeric `min_capacity` / `max_capacity`.

User-facing facility selection uses:

1. Facility Type / Product
2. schedule/rate
3. quantity
4. estimated users
5. room occupant names where applicable

`FacilityRequirementIntent` and `FacilityRequirementGroup` capture that plan. `FacilityAvailabilityService` checks availability, and `FacilityAssignmentService` locks candidate facilities and automatically assigns exact units. `FacilityScheduleBlockService` owns the authoritative collision/ownership records.

There is no standalone primary `/plan-facilities` workflow and public guests do not choose physical facility numbers.

### Parent / detail model

A Reservation or Booking is one parent financial transaction containing many facility details. Each detail may be rescheduled, upgraded/transferred, cancelled, or assigned independently where the lifecycle allows it.

Room occupants are detail-owned. Every room occupant is named and tied to the exact assigned room. Authorized Admin/Cashier staff may correct occupant names after creation; those mutations are audited.

### Financial model

Committed detail rows store immutable pricing/occupancy snapshots. Master-data edits must never reprice historical committed transactions.

`TransactionLedgerService` derives:

`Charges - Discounts - Applied Credits - Verified Payments = Balance`

The ledger includes posted facility, amenity, fine, adjustment and payment state. Pending GCash is not settled money.

Same-transaction credits are represented by `TransactionCredit` and `TransactionCreditAllocation`. Credits cannot become a guest wallet, cannot be paid out, and cannot move to unrelated future transactions.

### Reservation payment

Reservation minimum downpayment is 50% of the final discounted total. More than 50% is allowed up to 100%. If the final total changes, the minimum is recomputed and verified-payment shortfall must be collected.

Public GCash:

1. reference number;
2. private proof upload;
3. Pending;
4. Cashier verifies or rejects;
5. only Verified affects the settled balance.

### Booking / Walk-In

Direct public Booking uses the same facility planner and requires full verified core payment.

Cashier direct Walk-In:

1. select facilities through the shared planner;
2. name all room occupants;
3. enter entrance/admission categories;
4. pay facility + admission core charges in full;
5. system creates/links the settled Entrance Slip;
6. booking becomes Checked-in;
7. optional rentable amenities may be added and remain as outstanding booking balance.

### Discounts

Automatic discounts must be Active, have non-null start/end validity, and cover the transaction date. Eligible discounts do not stack; the highest monetary benefit is selected.

### Facility operations

- Reschedule changes one detail's schedule only.
- Transfer selects a destination product; the system auto-assigns a physical unit.
- Upgrades are allowed and charge the difference.
- Downgrades/cheaper transfers are rejected.
- Cancelling one detail does not cancel the parent. Eligible paid value becomes same-parent transaction credit.

### Entrance Slip

Security Guard can create/manage ordinary Entrance Slips and edit them while pending admission. After acceptance/admission the slip is locked. Correction uses Void + Replacement with audit metadata; the old record remains immutable history.

Direct Walk-In admission may issue a Cashier-settled linked slip as part of the booking core payment.

Tourist data remains in schema for compatibility but is hidden from approved operational presentation.

### Inspection / fines

While an inspection request is In Progress, fines are `InspectionDraftFine` records only. They are invisible as Cashier liability and do not alter booking balance.

Complete Inspection runs atomically:

1. validate active request/drafts;
2. publish GuestFine rows;
3. create locked inspection findings;
4. recalculate booking ledger;
5. complete request;
6. mark drafts Published.

A failure rolls the transaction back. Completed findings have no ordinary edit/delete path.

### Storage and audit

GCash proofs use private storage. Authorized application routes serve protected files.

Audited entities are observed through the audit registry, including room occupant mutations. Activity Logs expose actor, record, IP, User-Agent and before/after values through an application modal.

## Approved navigation

### Admin

Dashboard; User; Facility; Amenity; Entrance Fee; Discount; Fine; Reports → Management Reports, Activity Logs.

### Cashier

Dashboard; Entrance Slip; Reservation → Reservation Management, Reservation Conversion; Booking → Booking Management, Check-in, Amenity Requests, Check-out; Payment → Payment Management, GCash Verification; Billing Statement; Reports & Work → Reports, Notifications, Action Center.

### Maintenance

Amenity Requests; Facility Inspections; Notifications; Action Center.

### Security Guard

Entrance Slip is the primary operational entry.

## UI rules

Required fields use a visible red/required marker in final forms, destructive operations use destructive treatment, important irreversible actions use application dialogs, operational flashes appear fixed at the top-right, selected transaction rows remain highlighted, and layouts remain responsive.
