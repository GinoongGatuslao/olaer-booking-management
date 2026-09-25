# Olaer Spring Resort Booking & Billing System — User Manual

**Current roles:** Admin, Cashier, Maintenance Staff, Security Guard, Public Guest.

> This manual describes the current multi-facility implementation. Older references to a Manager role, Facility Preset creation, public physical-unit selection, immediate inspection-fine posting, or visible Tourist presentation are obsolete.

## 1. Sign in

Staff sign in using their assigned account. The Remember Me option may keep the session remembered on the browser according to Laravel authentication behavior. Never share credentials.

Demo-only seeded staff accounts use usernames `admin`, `cashier`, `maintenance`, and `security` with password `password`. Production must use real credentials.

## 2. Admin

### User

Create/edit staff accounts and assign only approved roles. Manager is not assignable. User search is intended for identity/contact/status/role fields, not address.

### Facility

A Facility is a physical unit. Facility Product/Rate configuration remains normalized internally.

To add a similar unit, use **Clone Facility**:

1. choose a source facility;
2. review the copied configuration;
3. enter/review a new Facility Number and Name;
4. save.

The source Facility Number is never copied. Numeric minimum/maximum capacity is used. Rooms are strict; cottage/hall capacity remains recommendation/information under product policy.

### Amenity / Fine

Manage master data needed by operations. The old Usage presentation is not part of the approved table UI.

### Entrance Fee / Discount

Entrance fees must remain valid positive rates. Automatic discounts require Active status plus a start/end validity period covering the transaction date. If several qualify, the system uses the largest monetary benefit; automatic discounts do not stack.

### Reports / Activity Logs

Activity Logs provide a detail modal with actor, action, record, IP address, User-Agent and before/after values.

## 3. Public Guest

### Reservation

1. Open Reservation.
2. Enter total unique guests.
3. Add one or more facility requirement rows.
4. For each row choose Facility Product, schedule, quantity and estimated users.
5. Use another row for a different schedule.
6. For rooms, provide every occupant name.
7. Check availability/total.
8. Enter a GCash reference and upload private proof.
9. Pay at least 50% of the final discounted total; paying more up to 100% is allowed.
10. Submit.

The guest does not choose exact physical Facility Numbers. The system assigns available units.

GCash remains **Pending** until Cashier verification and does not count as settled balance while pending.

### Direct Booking

Direct public Booking uses the same planner but requires full core payment. The submitted GCash proof still requires Cashier verification.

## 4. Cashier

### Reservation Management

Create Reservations through the shared planner. One Reservation may contain many facility details.

Each active detail may be managed independently:

- **Reschedule:** changes that detail’s schedule only.
- **Transfer:** choose a destination product; the system auto-assigns an exact unit. Upgrades are allowed and charge the difference; cheaper/downgrade transfers are rejected.
- **Occupants:** authorized staff may correct room occupant names. The change is audited.
- **Cancel Facility:** cancels only that detail. Eligible paid value remains as same-reservation credit and cannot be refunded or transferred to another transaction.

Use whole-reservation cancellation only when the entire parent must be cancelled.

### Reservation Conversion

A Reservation becomes a Booking only according to the verified-payment conversion rules. Detail pricing snapshots, occupant assignments and schedule ownership must be preserved.

### Booking Management

One Booking may contain many facility details. Detail-level reschedule/transfer/cancel/occupant rules mirror the Reservation lifecycle where allowed.

### Direct Walk-In Booking

1. Open Booking → Create.
2. Select facilities with the shared planner.
3. Name room occupants.
4. Enable Walk-In.
5. Enter Adult/Child/PWD-Senior and Male/Female entrance counts. Category total and Male + Female must match the party count.
6. The system computes facility core + admission charge.
7. Select payment mode and fully settle that required core amount.
8. Optionally add rentable amenities.
9. Create the Walk-In.

The linked Entrance Slip is settled through the booking core payment and the Booking becomes Checked-in immediately. Optional amenity charges may remain outstanding and must be settled later.

### GCash Verification

Review reference/proof, then Verify or Reject. Only Verified payments reduce settled balance. Duplicate references are protected.

### Billing Statement

Search by guest/reference and choose a Reservation or Booking. The statement includes historical facility charges, discounts, amenities, posted fines, verified/pending payment history, GCash references, adjustments, same-transaction credits, cancellations, linked entrance information, and reconciled balance.

## 5. Security Guard

Entrance Slip is the primary Security workflow.

### Before admission

The Security Guard who created the slip may edit it while it remains pending/unlocked.

### After admission/lock

Do not edit locked history. If a correction is necessary:

1. open the locked slip;
2. choose **Correct locked slip**;
3. enter the void/correction reason;
4. review current replacement values;
5. confirm **Void & Create Replacement**.

The old slip remains as Voided history and points to the replacement. Verified value may carry only to that replacement; it does not become a general guest wallet.

Tourist remains a backend compatibility field but is not presented in the approved interface.

## 6. Maintenance Staff

### Amenity Requests

Accept requests through the application confirmation dialog, deliver them, then confirm delivery. Avoid browser refresh/navigation during a mutation.

### Facility Inspections

A Cashier-created inspection request must be assigned/accepted first.

While inspection is open:

1. add or update draft fines;
2. remove incorrect draft fines if needed;
3. verify checklist findings.

Draft fines are **not payable** and do **not** change Booking balance.

To finish:

- **Complete as No Damage** when there are no draft fines; or
- **Complete Inspection** to atomically publish all drafts.

Once completed, published fines become Cashier liability, Booking balance updates, and findings are locked against ordinary changes.

## 7. Same-transaction credit

Credit created by an individual facility cancellation:

- stays under the same Reservation or Booking;
- can be allocated only to legitimate charges in that same parent transaction;
- cannot be cashed out;
- cannot become a reusable guest wallet;
- cannot carry to an unrelated future booking.

## 8. Printing and notifications

Operational flashes appear fixed at the top-right. Dedicated print tabs use Back/Close behavior that closes the print tab when possible and falls back safely.

## 9. Support and data integrity

Do not manually edit financial database rows to “fix” balances. Historical transaction amounts come from snapshots/ledger state, not today’s master prices. Escalate unexplained migration collisions, schedule ownership conflicts, or ledger differences for technical review.
