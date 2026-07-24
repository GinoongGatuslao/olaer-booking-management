# User Acceptance Test Checklist

Use this checklist with a resort representative, adviser, or evaluator.

Record:

```text
Date:
Tester:
Role:
Environment:
Application version or commit:
```

Mark each item:

```text
PASS
FAIL
NOT TESTED
NOT APPLICABLE
```

## Authentication and access

| Test | Expected result | Result |
|---|---|---|
| Valid staff login | User reaches the correct role dashboard | |
| Invalid login | Access is denied with a safe message | |
| Cashier opens Admin-only page | Access is denied | |
| Maintenance opens Cashier-only page | Access is denied | |
| Deactivated account attempts login | Access is denied | |

## Reservation

| Test | Expected result | Result |
|---|---|---|
| Create valid reservation | Active reservation is created | |
| Select conflicting facility dates | Conflict is rejected | |
| Cancel unpaid active reservation | Status becomes Cancelled | |
| Cancel with no reason | Validation error appears | |
| Cancel reservation with verified payment | Cancellation is blocked | |
| Cancel converted reservation | Cancellation is blocked | |
| No-show command processes expired unpaid hold | Status becomes No-show | |

## Booking and payment

| Test | Expected result | Result |
|---|---|---|
| Convert valid reservation | One booking is created | |
| Repeat conversion | Duplicate booking is blocked | |
| Record exact Cashier payment | Payment becomes Verified | |
| Record payment above amount due | Payment is rejected | |
| Verify pending GCash proof | Target becomes paid or guaranteed | |
| Reject pending GCash proof | Reason is saved and state is released | |
| Verify same payment twice | Duplicate verification is blocked | |

## Entrance slip

| Test | Expected result | Result |
|---|---|---|
| Security Guard creates valid slip | Slip starts Unpaid | |
| Gender total does not equal categorized guests | Validation error appears | |
| Tourist count exceeds guests | Validation error appears | |
| Cashier records partial entrance payment | Payment is rejected | |
| Cashier records exact full payment | Slip becomes Paid | |

## Check-in

| Test | Expected result | Result |
|---|---|---|
| Check in fully paid booking | Detail becomes Checked-in | |
| Check in unpaid booking | Check-in is blocked | |
| Repeated check-in | Duplicate transition is blocked or safely idempotent | |
| Check in one detail in multi-facility booking | Booking reflects partial occupancy | |

## Amenity request

| Test | Expected result | Result |
|---|---|---|
| Cashier requests amenity | Request starts Pending | |
| Charge exceeds no constraint and valid quantity | Booking balance increases correctly | |
| Maintenance accepts request | Status becomes Delivering | |
| Different maintenance user tries takeover | Action is blocked | |
| Assigned maintenance marks delivered | Status becomes Delivered | |
| Deliver before payment | Delivery succeeds and balance remains due | |
| Cancel pending request | Booking charge is reversed once | |
| Cancel delivered request | Cancellation is blocked | |

## Inspection and fine

| Test | Expected result | Result |
|---|---|---|
| Cashier requests inspection | Request starts Pending | |
| Maintenance accepts | Request becomes In Progress | |
| Different maintenance user completes | Action is blocked | |
| Complete without inspection result | Action is blocked | |
| Record clean inspection | Request becomes Completed | |
| Record damaged item | Guest fine is created | |
| Edit fine quantity | Only the difference changes booking balance | |
| Repeat same fine submission | Duplicate charge is prevented | |

## Checkout

| Test | Expected result | Result |
|---|---|---|
| Checkout without inspection | Blocked | |
| Checkout with incomplete inspection | Blocked | |
| Checkout with unpaid amenity | Blocked | |
| Checkout with unpaid fine | Blocked | |
| Record exact final payment | Amount due becomes zero | |
| Checkout after all requirements | Detail becomes Checked-out | |
| Checkout all details | Booking becomes Checked-out | |
| Successful checkout | Facility becomes Available | |

## Reports and audit

| Test | Expected result | Result |
|---|---|---|
| Pending payment in revenue report | Excluded | |
| Rejected payment in revenue report | Excluded | |
| Verified payment in revenue report | Included | |
| Search report rows | Official date-range summary remains clear | |
| Admin views outstanding balances | Operational balances display separately | |
| Cashier views handled revenue | Only handled or verified payments are included | |
| Verify payment | Activity log shows semantic Verified action | |
| System no-show | Activity log actor displays System | |
| Inspect sensitive values | Password and proof path are redacted | |

## Acceptance sign-off

```text
Critical failures:
Non-critical issues:
Recommended improvements:
Tester signature:
Project representative:
Date:
```
