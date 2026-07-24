# Capstone Defense and Demonstration Guide

## Demonstration objective

Show that the system supports the real operational sequence of Olaer Spring Resort from reservation or walk-in processing through payment, occupancy, amenity delivery, inspection, final billing, checkout, reporting, and audit review.

The demonstration should focus on business controls, not only interface appearance.

## Recommended demonstration data

Prepare these accounts:

```text
Admin account
Manager account
Cashier account
Maintenance Staff account
Security Guard account
```

Prepare at least:

```text
2 available facilities
1 rentable amenity
1 included facility amenity
1 fine definition
Cash payment mode
GCash payment mode
Entrance-fee rates
```

Do not use personally identifying real guest information during the defense.

## Demonstration sequence

### Scenario 1 — Entrance slip

1. Log in as Security Guard.
2. Create an entrance slip with valid guest categories.
3. Show that the slip starts as `Unpaid`.
4. Log in as Cashier.
5. Record the exact full entrance payment.
6. Show that the slip becomes `Paid`.
7. Show or print the entrance document.

What this proves:

```text
- role separation
- headcount validation
- payment gating
- entrance-slip workflow
```

### Scenario 2 — Reservation cancellation

1. Create an unpaid active reservation.
2. Show that the facility date range is held.
3. Cancel the reservation as Cashier with a reason.
4. Show that the cancellation preserves financial history.
5. Show that the date range becomes available again.

What this proves:

```text
- temporary reservation hold
- cancellation control
- availability release
- no false refund transaction
```

### Scenario 3 — GCash reservation or booking

1. Create a guest transaction requiring GCash verification.
2. Show the pending payment and uploaded proof.
3. Open the Cashier verification screen.
4. Compare target, amount, reference, and proof.
5. Verify the payment.
6. Show the guaranteed booking state.

Optional rejection variant:

```text
Reject a separate pending GCash payment
→ record a rejection reason
→ show availability release
```

What this proves:

```text
- guest payment review
- verified revenue recognition
- rejected-payment control
```

### Scenario 4 — Check-in

1. Open a fully paid booking.
2. Check in one or more facilities.
3. Show the Booking Detail status.
4. Show the facility operational status as occupied.
5. Show that an unpaid or invalid booking cannot be checked in.

What this proves:

```text
- payment gating
- occupancy control
- detail-level status management
```

### Scenario 5 — Amenity delivery before payment

1. While the guest is checked in, request a rentable amenity.
2. Show that the booking amount due increases.
3. Log in as Maintenance Staff.
4. Accept the request.
5. Mark it delivered without collecting payment.
6. Show that the request is `Delivered`.
7. Show that the charge remains payable during final billing.

State the rule clearly:

> Amenity delivery does not require advance payment. The charge is settled during checkout or final billing.

What this proves:

```text
- realistic resort service delivery
- deferred amenity billing
- role-based request handoff
```

### Scenario 6 — Clean inspection and checkout

1. Log in as Cashier.
2. Request checkout inspection.
3. Log in as Maintenance Staff.
4. Accept the request.
5. Record a clean inspection.
6. Return to Cashier.
7. Check out the facility.
8. Show that the facility becomes available.

What this proves:

```text
- Cashier-triggered inspection
- Maintenance assignment
- checkout inspection gate
- facility release
```

### Scenario 7 — Damage fine and final payment

1. Use a checked-in booking with an included or delivered amenity.
2. Request and accept checkout inspection.
3. Record one damaged or missing item.
4. Show the generated fine.
5. Show that the Booking total and amount due increase.
6. Attempt checkout before payment and show that it is blocked.
7. Record the exact final payment.
8. Complete checkout successfully.

What this proves:

```text
- fine traceability
- balance mutation
- final-payment requirement
- checkout blocking
```

### Scenario 8 — Reports

1. Open the Admin revenue report.
2. Select a date range.
3. Show verified revenue.
4. Show payment-mode and target breakdowns.
5. Show outstanding balances separately.
6. Demonstrate search without claiming that search changes the official date-range total.
7. Open Cashier reports and show Cashier-scoped revenue.

What this proves:

```text
- verified-payment revenue rule
- management reporting
- Cashier scope
- separation of revenue and receivables
```

### Scenario 9 — Activity logs

1. Open Admin Activity Logs.
2. Filter by module and action.
3. Locate the payment verification, checkout, or cancellation performed earlier.
4. Show actor, subject, old values, and new values.
5. Show a system-generated no-show action when available.
6. Confirm that passwords and payment-proof paths are not exposed.

What this proves:

```text
- accountability
- business-event traceability
- sensitive-data redaction
```

## Questions the panel may ask

### Why is a reservation not immediately a booking?

A reservation is a temporary hold. A booking is the guaranteed stay produced after the required payment and verification conditions are satisfied.

### Why can amenities be delivered before payment?

This reflects resort operations. Guests may request services during their stay, and the system adds the charges to the final bill rather than blocking service delivery.

### Why is inspection required before checkout?

The resort must confirm the condition and completeness of facilities and amenities before releasing the guest and returning the facility to available status.

### Why do you use transactions and row locks?

Several workflows change multiple related records. Transactions prevent partial updates, while row locks reduce duplicate or conflicting state changes.

### Why are pending GCash payments excluded from revenue?

The resort has not yet verified the payment. Only verified payments are recognized as received revenue.

### Does the system support refunds?

No. Refund processing is outside the approved scope. The workflow prevents cancellation of paid and confirmed transactions where a refund would be required.

### Why are activity logs non-blocking?

A logging problem should be reported, but it must not cause a valid resort transaction to fail after the business conditions are satisfied.

## Defense execution tips

```text
- reset demonstration data before the presentation
- keep all five role accounts ready
- open separate browser profiles or incognito windows per role
- prepare one clean-checkout case and one damage-fine case
- use short, realistic guest and facility names
- avoid improvising database corrections during the defense
- explain the business rule before clicking the action
- show the prevented action when demonstrating a control
- keep the automated test result ready as evidence
```
