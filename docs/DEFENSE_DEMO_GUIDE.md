# Defense / Demo Guide

Use seeded demo data only in a non-production environment.

## Demo accounts

- Admin: `admin` / `password`
- Cashier: `cashier` / `password`
- Maintenance: `maintenance` / `password`
- Security Guard: `security` / `password`

## Recommended demo sequence

1. **Admin / Facility**
   - show normalized product/rate configuration;
   - show numeric capacity;
   - clone a facility and point out that the new Facility Number is not copied.

2. **Public Reservation**
   - select two facility groups under one Reservation;
   - show availability counts and no physical-unit picker;
   - add room occupant names;
   - submit a 50%+ GCash deposit and show Pending status.

3. **Cashier / GCash**
   - verify the payment;
   - show only Verified value affects balance.

4. **Reservation detail operations**
   - reschedule one detail;
   - demonstrate that sibling schedules do not move;
   - show upgrade transfer with auto-assigned unit;
   - explain downgrade rejection;
   - cancel one detail and show same-transaction credit.

5. **Reservation Conversion**
   - convert when payment conditions are met;
   - show pricing snapshots/occupants preserved.

6. **Walk-In**
   - create multi-facility Walk-In;
   - enter entrance categories;
   - show required facility + admission core payment;
   - add an amenity without paying it;
   - create as Checked-in and show amenity remains balance.

7. **Security / Entrance Slip**
   - show pre-admission edit;
   - show locked correction uses Void + Replacement and preserves history.

8. **Maintenance / Inspection**
   - add a draft fine;
   - show booking balance does not change;
   - Complete Inspection;
   - show fine becomes payable and inspection locks.

9. **Billing**
   - search guest/reference;
   - choose a Reservation/Booking;
   - show facilities, payments/GCash, credits, adjustments, amenities/fines and reconciled balance.

10. **Audit / UI**
    - open Activity Log detail/User-Agent modal;
    - show approved role navigation;
    - show top-right operational notification;
    - open a print view and demonstrate Back/Close behavior.

## Key invariants to explain

- historical committed values use snapshots;
- schedule blocks prevent double allocation;
- Pending GCash is not settled cash;
- transaction credit never becomes a guest wallet;
- downgrade transfer is prohibited;
- draft inspection fines are invisible to Cashier liability;
- locked records are corrected with auditable replacement rather than destructive edits.
