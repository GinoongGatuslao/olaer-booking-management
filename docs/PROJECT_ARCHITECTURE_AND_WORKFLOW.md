# Olaer Spring Resort — Project Architecture and Workflow

## Project title

**Web-Based Booking Management with Billing System for Olaer Spring Resort**

## Technology stack

```text
Laravel 13
Livewire Volt
TALL Stack
Tailwind CSS
Alpine.js
Flux free components
MySQL in production
SQLite-compatible automated tests
```

Volt components must remain class-based single-file components:

```php
use Livewire\Volt\Component;

new class extends Component
{
    // Component state and actions
};
```

Functional or closure-style Volt components are outside the project standard.

## Roles

The system uses these exact role names:

```text
Admin
Manager
Cashier
Maintenance Staff
Security Guard
```

### Admin

```text
- manages staff accounts and core configuration
- reviews reports
- reviews activity logs
- may access management-level operational information
```

### Manager

```text
- supervises resort operations
- reviews reports and operational status
- assists with authorized management actions
```

### Cashier

```text
- manages reservations and bookings
- records and verifies payments
- handles check-in and checkout
- requests maintenance inspection before checkout
- records final billing payments
- issues and prints operational documents
```

### Maintenance Staff

```text
- accepts amenity-delivery requests
- marks amenity requests delivered
- accepts checkout inspection requests
- records inspection results
- records fines for missing or damaged items
```

### Security Guard

```text
- creates entrance slips
- records guest-category headcounts
- sends unpaid slips to the Cashier for payment
```

## Canonical business rules

### Reservation

A reservation is a temporary facility hold. It is not automatically a guaranteed booking.

```text
Active
→ Cancelled
→ Converted
→ No-show
→ Payment Rejected
```

An unpaid active reservation may be cancelled without a refund because no payment has been accepted.

A reservation with a verified payment must not be silently cancelled.

A converted reservation must not be converted or cancelled again.

### Booking

A booking represents a guaranteed stay.

```text
Booked
→ Checked-in / Partially Checked-in
→ Partially Checked-out
→ Checked-out
```

A fully paid and confirmed booking is not cancelled through the reservation-cancellation workflow.

The system does not implement refunds.

### GCash guest payment

```text
Guest submits exact full payment and proof
→ Payment becomes Pending
→ Cashier verifies or rejects
→ Verified payment guarantees the booking
→ Rejected payment releases availability
```

The proof, reference number, payment amount, and booking target must be reviewed together.

### Amenities

```text
Cashier requests amenity
→ Request becomes Pending
→ Maintenance accepts
→ Request becomes Delivering
→ Maintenance delivers
→ Request becomes Delivered
```

Amenity delivery does not require advance payment.

Amenity charges are added to the booking balance and are paid during checkout or final billing.

### Checkout inspection

```text
Cashier requests inspection
→ Request becomes Pending
→ Maintenance accepts
→ Request becomes In Progress
→ Maintenance records inspection
→ Request becomes Completed
```

Only the assigned Maintenance Staff member may complete the request.

A completed request must have a real facility-inspection result.

### Fines

```text
Maintenance identifies a missing or damaged item
→ Guest fine is created or updated
→ Booking total and amount due increase
→ Cashier collects the balance before checkout
```

Repeated updates must apply only the difference between the old and new fine totals.

### Checkout

A facility cannot be checked out until:

```text
- a Cashier-created inspection request exists
- Maintenance has completed the inspection
- unpaid amenity charges have been settled
- fines have been settled
- booking amount_due is zero
```

Successful checkout updates:

```text
Booking detail status → Checked-out
Booking status → Checked-out when all details are checked out
Facility status → Available
```

## Availability

Availability is date-range based.

Non-blocking statuses include records such as:

```text
Cancelled
Converted
No-show
Payment Rejected
Rejected
```

Facility operational status and date-range availability are separate concerns.

## Financial reporting

Recognized revenue comes only from verified payments:

```text
SUM(tbl_payment.amount_paid)
WHERE LOWER(payment_status) = 'verified'
```

Pending and rejected payments are excluded.

Outstanding balances are operational receivables and are not recognized revenue.

## Audit logging

Operational model events should record:

```text
actor
action
module
subject
description
old values
new values
IP address
user agent
timestamp
```

Sensitive values must not be stored directly:

```text
password → [REDACTED]
remember_token → [REDACTED]
proof_of_payment_path → [FILE STORED]
```

Scheduled actions may use a null actor and appear as `System`.

## Concurrency and transaction rules

Use database transactions for changes that affect:

```text
status
amount_due
total_price
availability
payment verification
reservation conversion
inspection completion
fine totals
checkout
```

Use `lockForUpdate()` for critical state transitions that may be triggered twice or concurrently.

## Database naming rule

The correct Booking Detail primary key is:

```text
booking_details_id
```

Do not introduce:

```text
booking_detail_id
```
