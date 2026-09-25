# User Acceptance Test Checklist

Record tester, date, environment, build/commit and evidence for every case. A failed critical case blocks release.

## Admin

- [ ] Admin can sign in and Remember Me behaves correctly.
- [ ] No Manager role is assignable or routed.
- [ ] User list sorts/searches correctly; address is not exposed as a search dimension.
- [ ] Clone Facility copies configuration but requires a distinct Facility Number.
- [ ] Facility numeric min/max capacity displays correctly.
- [ ] Amenity and Fine tables do not show the removed Usage presentation.
- [ ] Automatic discount without valid start/end dates does not auto-apply.
- [ ] Activity Log detail modal shows actor, IP, User-Agent, before/after.
- [ ] Admin navigation matches approved structure.

## Public Reservation

- [ ] Guest can select multiple facility products/quantities/schedules under one Reservation.
- [ ] Guest cannot choose exact physical unit numbers.
- [ ] Availability count matches actual assignable units.
- [ ] Concurrent assignment cannot double-book one schedule block.
- [ ] Every room occupant name is required and attached to a specific assigned room.
- [ ] Cottage/hall capacity is informational; room maximum is strict.
- [ ] Final discounted total is calculated from snapshots.
- [ ] GCash amount below 50% is rejected.
- [ ] GCash above 50% up to 100% is accepted.
- [ ] Pending GCash does not reduce settled balance.
- [ ] Cashier verify/reject works and duplicate reference integrity holds.

## Cashier Reservation

- [ ] Create flow uses the shared facility planner.
- [ ] One parent can contain multiple facility details.
- [ ] Rescheduling one detail leaves other schedules unchanged.
- [ ] Transfer chooses product and auto-assigns a physical unit.
- [ ] Upgrade charges only the required difference.
- [ ] Cheaper/downgrade transfer is rejected.
- [ ] Cancelling one detail leaves parent/other details active.
- [ ] Paid cancellation value becomes same-reservation credit only.
- [ ] Staff occupant-name correction is audited.
- [ ] Whole-parent cancellation remains separate.

## Conversion / Booking

- [ ] Reservation conversion preserves pricing snapshots, occupants and schedule ownership.
- [ ] Booking shows every facility detail.
- [ ] Booking detail reschedule/upgrade/cancel rules do not mutate sibling details.
- [ ] Same-booking credit cannot be applied to another Booking.
- [ ] Historical master-rate changes do not reprice the Booking.

## Direct Walk-In

- [ ] Cashier can create a multi-facility Walk-In.
- [ ] Entrance category total equals party count.
- [ ] Male + Female equals party count.
- [ ] Facility + admission core amount must be fully paid.
- [ ] Linked Entrance Slip is created/settled and Booking becomes Checked-in.
- [ ] Optional amenities can be added at creation.
- [ ] Unpaid amenity remains active Booking balance after admission.

## Entrance Slip

- [ ] Security can create/edit a pending slip.
- [ ] After admission/payment the slip is locked.
- [ ] Locked correction requires void reason.
- [ ] Old slip becomes Voided and remains immutable history.
- [ ] Replacement is linked.
- [ ] Verified value carries only to replacement when applicable.
- [ ] Tourist is hidden in approved UI/print/report presentation while backend compatibility data remains.

## Maintenance / Inspection

- [ ] Maintenance accept/deliver actions use application dialogs.
- [ ] Recording a fine while inspection is open creates only a draft.
- [ ] Draft fine is not visible as payable GuestFine.
- [ ] Draft fine does not change Booking total/balance.
- [ ] Draft can be corrected/removed while open.
- [ ] Complete Inspection uses an application confirmation dialog.
- [ ] Complete Inspection publishes all drafts atomically.
- [ ] A simulated failure during completion posts nothing.
- [ ] Completed findings/fines cannot be ordinarily edited/deleted.
- [ ] No-damage completion posts no fine.

## Billing / Payments

- [ ] Historical Transactions lists Reservations and Bookings by guest/reference.
- [ ] Detailed statement shows facilities, discounts, amenities, posted fines, payments/GCash, adjustments, credits and cancellations.
- [ ] Ledger reconciles: Charges − Discounts − Applied Credits − Verified Payments = Balance.
- [ ] Cancelled detail credit is not double-counted as both payment and credit.
- [ ] Printed Booking statement reconciles to on-screen ledger.
- [ ] Print Back closes a dedicated tab or falls back safely.

## Navigation / responsive UI

- [ ] Admin menu matches approved structure.
- [ ] Cashier menu matches approved structure.
- [ ] Maintenance menu is ungrouped as approved.
- [ ] Security focuses on Entrance Slip.
- [ ] Required fields are visibly marked in transaction forms.
- [ ] Destructive actions are visually destructive.
- [ ] User status, Checkout, Maintenance accept/deliver, and Inspection completion use app dialogs.
- [ ] Operational notifications appear fixed top-right.
- [ ] Selected transaction/detail remains visibly highlighted where selection is used.
- [ ] Desktop, tablet and mobile layouts remain usable.

## Release/deployment

- [ ] Final GitHub Actions workflow is green at exact release commit.
- [ ] SQLite test/build job passes.
- [ ] MySQL 8.4 migration + full regression job passes.
- [ ] Upgrade migration tested on realistic old data.
- [ ] Legacy schedule-block backfill has no unresolved collision.
- [ ] Manager preflight has no unresolved account.
- [ ] storage-4-private proof upload/read/authorization validated.
- [ ] storage-4-public validated.
- [ ] queue worker and scheduler validated.
- [ ] SMTP/email validated.
- [ ] upload limits validated.
- [ ] print/PDF views validated.
- [ ] backup and restore drill completed.
- [ ] production logs/monitoring checked.
