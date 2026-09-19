# Olaer Spring Resort Booking Management with Billing System
## Comprehensive User Manual

**Document version:** 1.0  
**Functional baseline:** `UX-updates` branch, commit `872c4629519aa8c6960660953c76338a1089ac4d`  
**Baseline date:** September 19, 2026  
**System:** Web-Based Booking Management with Billing System for Olaer Spring Resort

> This manual is written for actual system users. All staff walkthroughs use the seeded demo accounts included in the project. Public guests do not require an account.

---

## 1. Purpose of This Manual

This manual explains how to operate the complete Olaer Spring Resort Booking Management with Billing System from the perspective of guests, administrators, managers, cashiers, maintenance staff, and security guards.

It covers:

- staff login and password recovery;
- public reservations and direct online bookings;
- multi-facility reservation planning;
- reservation management and confirmation lookup;
- entrance-slip creation, payment, and admission;
- reservation creation, payment, conversion, rescheduling, and cancellation;
- paid booking creation and booking modifications;
- GCash proof verification;
- cashier payment recording and receipt printing;
- check-in and facility occupancy;
- amenity requests and delivery;
- checkout inspection, damage fines, final payment, and checkout;
- master-data administration;
- reports and activity logs;
- user-account administration;
- personal settings and security options; and
- common errors, business rules, and recommended daily procedures.

This is an operational user manual, not a programming or deployment guide.

---

## 2. Demo Accounts Used Throughout This Manual

All seeded staff accounts use the password **`password`** in the demo environment.

| Role | Demo Name | Username | Email | Password |
|---|---|---|---|---|
| Admin | System Admin | `admin` | `admin@olaer.test` | `password` |
| Manager | System Manager | `manager` | `manager@olaer.test` | `password` |
| Cashier | Demo Cashier | `cashier` | `cashier@olaer.test` | `password` |
| Maintenance Staff | Demo Maintenance | `maintenance` | `maintenance@olaer.test` | `password` |
| Security Guard | Demo Security | `security` | `security@olaer.test` | `password` |

> **Demo-only security note:** Change these passwords before using a seeded database in any real or public production environment.

### Public guest used in examples

Public guests do not log in. Where sample guest information is needed, this manual uses a fictional test profile such as:

- Name: **Demo Guest**
- Email: **demo.guest@example.com**
- Contact: **09123456789**

Do not use real personal information for demonstrations unless necessary and authorized.

---

## 3. User Roles and Responsibility Matrix

| Function | Admin | Manager | Cashier | Maintenance | Security | Public Guest |
|---|---|---|---|---|---|---|
| Admin dashboard | Yes | Yes | No | No | No | No |
| Entrance-fee configuration | Yes | Yes | No | No | No | No |
| Discount configuration | Yes | Yes | No | No | No | No |
| Facility management | Yes | Yes | No | No | No | No |
| Amenity catalog | Yes | Yes | No | No | No | No |
| Fine and damage-type setup | Yes | Yes | No | No | No | No |
| Staff user management | Yes | Yes | No | No | No | No |
| Management reports | Yes | Yes | No | No | No | No |
| Activity logs | Yes | Yes | No | No | No | No |
| Reservation operations | No | No | Yes | No | No | Create/manage own public reservation |
| Booking operations | View details when permitted | View details when permitted | Yes | No | No | Create direct online booking |
| Payment recording | No | No | Yes | No | No | Submit GCash proof for direct booking |
| GCash verification | No | No | Yes | No | No | No |
| Check-in and checkout | No | No | Yes | No | No | No |
| Amenity request creation | No | No | Yes | Yes, for checked-in stays | No | No |
| Amenity delivery | No | No | No | Yes | No | No |
| Inspection and fines | No | No | No | Yes | No | No |
| Entrance-slip creation | No | No | No | No | Yes | No |
| Entrance-slip payment/admission | No | No | Yes | No | No | No |

### Role separation principle

The system intentionally separates duties. For example:

1. **Security Guard** records entrance headcount and creates the entrance slip.
2. **Cashier** records the exact payment and admits the guest.
3. **Cashier** initiates checkout inspection.
4. **Maintenance Staff** performs the inspection and records damage or missing-item fines.
5. **Cashier** collects any remaining balance and completes checkout.

This separation creates traceability and reduces accidental or unauthorized status changes.

---

## 4. Quick Start

### 4.1 Staff login

1. Open the system login page.
2. Enter the seeded username for the role you want to demonstrate.
3. Enter `password`.
4. Optionally select **Remember me**.
5. Click **Login**.
6. The system redirects the user to the correct role dashboard.

Example for Cashier:

- Username: `cashier`
- Password: `password`

### 4.2 Incorrect or inactive accounts

- An incorrect username or password is rejected.
- An inactive account cannot remain logged in.
- Role-restricted pages return access denied when opened by the wrong role.

### 4.3 Log out

Use the account/user menu and select **Logout**. The authenticated session is invalidated.

### 4.4 Navigation behavior

The staff interface uses a role-specific sidebar. Some menu items show live badges for pending work such as:

- pending GCash verifications;
- checkout work;
- unpaid bookings;
- amenity delivery requests;
- inspection requests; and
- unpaid entrance slips.

Search, filtering, sorting, pagination, status badges, and print actions are used consistently throughout the system.

---

## 5. Authentication, Password Recovery, and Personal Settings

### 5.1 Forgot password

Use this when a staff member cannot remember the account password.

1. From the login page, click **Forgot password?**
2. Enter the staff username or email address.
3. Click **Send reset code**.
4. If the account is active and has a valid email address, the system sends a six-digit code.
5. Enter the six-digit code and click **Verify code**.
6. After verification, enter a new password and confirmation.
7. The reset password must contain at least 8 characters and include uppercase, lowercase, a number, and a symbol.
8. Click **Change password**.
9. Return to Login and sign in using the new password.

Important behavior:

- The reset code expires after 10 minutes.
- Only the newest code is valid.
- Excessive incorrect attempts invalidate the reset attempt.
- The page does not reveal whether an unknown identifier belongs to a user.

### 5.2 Profile settings

Any logged-in staff user may open **Settings > Profile**.

The page shows:

- display name;
- username;
- email; and
- assigned role.

Staff identity, role, contact details, and account status are managed centrally by an Admin or Manager. The user does not directly edit those values on the profile page.

### 5.3 Appearance

Use **Settings > Appearance** to change the application appearance according to the options enabled in the interface.

### 5.4 Security settings

Use **Settings > Security** to update the current password.

Depending on enabled feature configuration, the page can also expose two-factor authentication and passkey controls. These are not required for the seeded demo-account walkthroughs in this manual.

---

---

## 6. Public Guest Manual

No staff account is required for this chapter.

### 6.1 Public homepage

The public homepage is the starting point for a guest. It introduces the resort and provides paths to:

- plan facilities;
- create a reservation;
- create a direct online booking;
- manage an existing reservation; and
- find a reservation or booking confirmation.

### 6.2 Multi-facility reservation planner

Use **Plan Facilities** when one party needs multiple facility groups in one reservation, for example several cottages plus a room.

#### Step A - Set the party size

1. Open **Plan Facilities**.
2. Enter **Total unique guests**.

This value represents the whole party, not the sum of duplicated headcounts across facility groups.

#### Step B - Add facility requirement groups

For each requirement group:

1. Select **Facility type and name**.
2. Select the **Schedule** or rate.
3. Enter the **Quantity** of that facility product.
4. Enter **Estimated users** for the group.
5. Enter the check-in/use date and check-out/end date.
6. Review the live availability count.
7. Click **Add another facility group** when another type, rate, or schedule is needed.
8. Remove a group if it is no longer required.

Click **Check and save availability** to validate the plan against current reservations and bookings.

> The planner does not permanently choose physical facility numbers while the guest is still drafting. It checks availability by product and schedule, then assigns the actual available physical units only when the reservation plan is submitted.

#### Step C - Enter the primary guest

Enter:

- first, middle, and last name;
- email;
- contact number;
- province;
- city/municipality;
- barangay; and
- purok/street.

#### Step D - Enter paid room extra guests

Rooms include 4 guests and allow a strict maximum of 10 guests per room. When a room allocation exceeds the included 4 guests, the system requires the corresponding paid extra-guest names.

The current room extra-guest charge is **PHP 100.00 per paid extra guest**.

Cottages and function halls use recommended capacity guidance rather than the strict room rule.

#### Step E - Submit

1. Review all groups and availability.
2. Click **Submit reservation plan**.
3. The system rechecks availability under transaction control.
4. Physical facilities are assigned.
5. One reservation is created with one or more reservation details.
6. The plan becomes fulfilled and cannot be submitted a second time.
7. The guest is sent to the reservation success page.

If availability changed before final submission, the system rejects the submission and the guest must refresh the plan.

### 6.3 Single-facility reservation

Use **Reserve** when the guest wants to reserve one specific facility.

1. Open **Reserve**.
2. Enter guest information.
3. Select the facility type.
4. Select the rate/schedule.
5. Select check-in/use date and check-out/end date.
6. Select an available physical facility.
7. Enter total guests, including the primary guest.
8. For a room above the included guest count, enter the paid extra-guest names.
9. Review the reservation summary.
10. Submit the reservation.

The result is an **Active reservation**, not yet a guaranteed booking unless the required payment and conversion process is completed.

### 6.4 Direct online booking with GCash

Use **Book** when the guest is ready to pay the full amount immediately through GCash.

1. Open **Book**.
2. Select the check-in and check-out dates.
3. Enter the expected check-in time.
4. Select facility type, rate, and available facility.
5. Enter total guests and required room extra-guest names.
6. Enter guest contact and address information.
7. Review the calculated booking total.
8. Enter the **exact full GCash amount**.
9. Enter the GCash reference number.
10. Upload proof of payment.
11. Submit the booking.

Accepted proof file types:

- JPG/JPEG;
- PNG; or
- PDF.

Maximum proof size: **4 MB**.

After submission:

- the payment is **Pending**;
- the booking is **Pending Verification**;
- the selected facility is protected from conflicting bookings while verification is pending;
- the Cashier reviews the proof; and
- only a verified payment is treated as received revenue.

If the Cashier rejects the proof, the booking becomes **Payment Rejected** and the facility is released according to the booking availability rules.

### 6.5 Manage an existing reservation

Use **Manage Reservation** to modify or cancel an eligible public reservation securely.

1. Enter the reservation reference number.
2. Enter the email used for the reservation.
3. Request the one-time code.
4. Check the guest email for the six-digit OTP.
5. Enter the OTP.
6. After successful verification, review the reservation.

The guest may be offered actions such as:

- changing visit dates;
- selecting a different facility type/rate/available facility;
- updating total guest count and required room extra-guest names; or
- cancelling the reservation with a reason.

Online management restrictions:

- only **Active** reservations can be managed online;
- a reservation with a **Verified** payment cannot be changed or cancelled online;
- paid/converted/cancelled/no-show records require staff assistance or are no longer editable; and
- the OTP expires after 10 minutes, with a limited number of attempts.

### 6.6 Find a confirmation

Use **Confirmation** when the guest needs to retrieve an existing record.

1. Choose **Reservation** or **Booking**.
2. Enter the reference number.
3. Enter the guest email.
4. Click the search action.
5. Review the result.
6. Use **Print** when a printable copy is needed.

Booking confirmation details can include:

- booking status;
- facility details and facility-level status;
- payment mode and payment status; and
- amenity-request status when present.

---

---

## 7. Admin and Manager Manual

**Demo account for Admin examples**

- Username: `admin`
- Password: `password`

**Alternative Manager demo account**

- Username: `manager`
- Password: `password`

Admin and Manager share the same primary management routes in the current system.

### 7.1 Admin/Manager dashboard

After login, review the dashboard first. It is intended to summarize operational status and provide entry points to master data, reports, logs, and user administration.

### 7.2 Entrance Fee Management

Use **Financial Configuration > Entrance Fees**.

Current seeded categories are:

| Category | Seeded Rate |
|---|---:|
| Adult | PHP 100.00 |
| Children | PHP 75.00 |
| Senior Citizen / PWD | PHP 80.00 |

To change a rate:

1. Find the entrance-fee category.
2. Click **Edit rate**.
3. Enter the new **Entrance fee rate**.
4. The value must be greater than zero; the UI minimum is PHP 0.01.
5. Click **Save rate**.

The system snapshots rates on entrance slips so later changes do not rewrite the historical amount charged on an existing slip.

### 7.3 Discount Management

Use **Financial Configuration > Discounts**.

The list can be searched and filtered by:

- status;
- effective timing;
- applicable category; and
- rows per page.

Applicable categories include:

- Adults;
- Children;
- Senior/PWD;
- Cottages;
- Rooms; and
- Function Halls.

To create a discount:

1. Click the create/new action.
2. Enter **Discount name**.
3. Enter **Discount percentage**.
4. Set **Status** to Active or Inactive.
5. Select the applicable category.
6. Enable **Use a validity period** only when a start/end period is required.
7. When validity is enabled, enter the start and end dates.
8. Save.

To edit a discount, select its edit action, update the fields, and save.

Only discounts that satisfy the current status, applicability, and validity conditions are considered by the operational pricing workflows.

### 7.4 Facility Management

Use **Master Data > Facilities**.

The list supports search by number, name, or category and filters by type and status.

Facility statuses shown in the system can include:

- Available;
- Unavailable;
- Booked; and
- Occupied.

#### Create physical facilities from a preset

1. Click **Add facilities**.
2. Select a **Facility preset**.
3. Enter **Quantity** from 1 to 100.
4. Select initial status: Available or Unavailable.
5. Optionally enter a **name prefix**.
6. Click **Create facilities**.

The system automatically creates unique physical facility numbers from the preset. The selected preset controls the facility product, capacity policy, schedule policy, and rate catalog.

#### Edit a physical facility

1. From the facility list, click **Edit**.
2. Update **Facility number** if required.
3. Update **Facility name**.
4. Update operational status only when the workflow allows it.
5. Click **Save changes**.

Important controls:

- facility number must be unique;
- the facility preset remains fixed on this screen;
- Booked and Occupied status are controlled by booking/check-in workflows and cannot be manually overridden through normal editing.

### 7.5 Current seeded facility catalog

| Product | Capacity Policy | Suggested/Strict Capacity | Rates |
|---|---|---|---|
| Small Cottage | Recommended | 4-6 | Day 300; Night 300; Both 600 |
| Medium Cottage | Recommended | 8-10 | Day 400; Night 400; Both 800 |
| Large Cottage | Recommended | 10-15 | Day 600; Night 600; Both 1,200 |
| Extra Large Cottage | Recommended | 15-25 | Day 900; Night 900; Both 1,800 |
| Standard Room | Strict | 4 included; 10 maximum | Overnight 2,500 |
| Function Hall 1 | Recommended | up to 25 | Whole Day 1,200 |
| Function Hall 2 | Recommended | up to 30 | Whole Day 1,500 |

Seeded physical inventory contains 132 cottages, 12 standard rooms, and 2 function halls.

### 7.6 Amenity Management

Use **Master Data > Amenities**.

Amenity types:

- **Rentable** - may be requested during a checked-in stay and adds a charge.
- **Inclusive** - belongs to a facility's standard contents and can be part of checkout inspection.

The list supports search, type filter, pagination, and sorting.

To create or edit an amenity:

1. Enter the amenity name.
2. Enter a description.
3. Select Amenity type.
4. Enter price.
5. Save.

Examples of seeded rentable amenities:

| Amenity | Price |
|---|---:|
| Table Set | PHP 300.00 |
| Small Table Set | PHP 150.00 |
| Chair | PHP 25.00 |
| Extra Bedding Set | PHP 300.00 |

### 7.7 Fines and Damage Types

Use **Financial Configuration > Fines**.

The module supports:

- Amenity fines;
- Situational fines; and
- Damage-type definitions.

Seeded damage types include:

- Damaged;
- Missing; and
- Stained.

Seeded situational fine examples include:

| Fine | Charge |
|---|---:|
| Stained Fabric | PHP 200.00 |
| Vomit in Room | PHP 1,000.00 |
| Vomit on Sheets or Fabric | PHP 2,000.00 |

To create an amenity fine, select the amenity and damage type, enter the charge and any required description, then save.

To create a situational fine, enter the situational fine name, description, and charge, then save.

### 7.8 Staff User Management

Use **Administration > Staff Users**.

The list supports:

- search;
- role filter;
- status filter;
- pagination;
- sorting;
- editing; and
- activation/deactivation.

To create a staff user:

1. Click **Create/New User**.
2. Enter first, middle, and last name.
3. Enter username and email.
4. Enter contact number.
5. Select role.
6. Select Active or Inactive status.
7. Enter address information.
8. Enter and confirm the initial password.
9. Save.

To deactivate an account:

1. Find the user.
2. Click **Deactivate**.
3. Confirm the action.

An inactive account cannot continue normal authenticated use.

> Staff registration is administrative. There is no public staff-registration workflow.

### 7.9 Management Reports

Use **Reports > Management Reports**.

Available report types include:

- Revenue;
- Booking Summary;
- Cancellation;
- Damaged Amenities;
- Available Facilities;
- Facility Utilization; and
- Tourism Enterprise Monthly.

Typical filters include:

- month and year;
- start and end date;
- facility type; and
- search of generated report rows.

Revenue reports are based on verified payments. Pending or rejected payment records are not treated as received revenue.

Search narrows the displayed generated rows; it should not be interpreted as redefining the official report period itself.

### 7.10 Activity Logs

Use **Reports > Activity Logs**.

Filters include:

- search text;
- module;
- action;
- record type;
- actor: staff or system;
- from/to date; and
- rows per page.

Use the log to trace business events such as:

- payment verification;
- reservation cancellation;
- checkout;
- user or master-data changes; and
- automated system actions such as no-show processing.

Sensitive values such as passwords and private proof-storage paths should not be exposed as ordinary audit data.

---

---

## 8. Cashier Manual

**Demo account for this chapter**

- Username: `cashier`
- Password: `password`

The Cashier role handles the majority of guest-facing financial and front-desk operations.

### 8.1 Cashier Dashboard

After login, review the dashboard for current work. Also use:

- **Notifications** for actionable alerts; and
- **Action Center** for shortcuts and live pending work.

The navigation may show badge counts for pending GCash, checkouts, and unpaid booking work.

### 8.2 Entrance Slip Payment and Admission

Use **Payments > Entrance Slips**.

#### Step A - Find the slip

1. Search by slip, guard, cashier, receipt, or reference.
2. Filter by status or date.
3. Select an Unpaid slip.

#### Step B - Review the details

Verify:

- entrance category quantities;
- total guest count;
- fee breakdown;
- discounts;
- total price; and
- amount due.

#### Step C - Record exact full payment

1. Select the mode of payment.
2. Cash is selected by default when available.
3. If GCash is used, enter the GCash reference number.
4. Record the full payment.

Entrance slips do not accept partial payment. The payment must exactly match the amount due.

After payment:

- slip status becomes **Paid**;
- amount due becomes zero; and
- a printable payment receipt is available.

#### Step D - Admit the guest

1. With the Paid slip selected, click **Admit guest**.
2. The system records the Cashier and admission time.
3. The entrance slip becomes locked against further Security editing.

You can print the entrance slip and the latest payment receipt.

### 8.3 Reservation Management

Use **Front Desk > Reservations**.

#### Create a reservation as Cashier

1. Click the create-reservation action.
2. Enter the guest representative's name, contact, optional email, and address.
3. Select facility type.
4. Select rate.
5. Select an available facility.
6. Enter check-in and check-out dates.
7. Apply an eligible discount if applicable.
8. Enter total guest count.
9. Enter required paid room extra-guest names.
10. Save the reservation.

#### Reschedule an active reservation

1. Find an Active reservation.
2. Click **Reschedule**.
3. Enter the new check-in date, new check-out date, and rate where required.
4. Save.

The system rechecks availability and pricing conditions.

#### Cancel an active reservation

1. Find the reservation.
2. Click **Cancel**.
3. Enter a cancellation reason.
4. Confirm cancellation.

Cancellation is blocked when the reservation has a verified payment that would require an unsupported refund workflow, or when the reservation is no longer in an editable state.

### 8.4 Reservation Payments

Use **Payments > Record Payment** and select **Reservations** as the payable type.

Active reservations may receive partial payments as long as:

- payment is greater than zero;
- payment does not exceed the remaining amount due; and
- the reservation remains in a payable status.

When amount due reaches zero, the reservation can be treated as fully paid and becomes eligible for conversion under the conversion rules.

### 8.5 Convert Reservation to Booking

Use **Front Desk > Reservation Conversion**.

1. Search the active/eligible reservation list.
2. Select the reservation.
3. Review the remaining balance.
4. If a balance remains, enter the **exact full remaining amount**.
5. Select payment mode.
6. Enter a GCash reference when required.
7. Click **Convert**.

The workflow:

- creates one booking from the reservation;
- carries over the guest, facility details, pricing snapshots, and room extra guests;
- records any final conversion payment;
- sets the booking balance to zero;
- sets booking status to Booked; and
- changes the reservation to Converted.

The same reservation cannot be converted twice.

### 8.6 Booking Management

Use **Front Desk > Bookings**.

#### Create a paid booking as Cashier

Use this for a guest who is ready for a guaranteed booking and is paying in full at the desk.

1. Click **Create Paid Booking**.
2. Enter guest and address information.
3. Select the available facility.
4. Select rate.
5. Apply an eligible discount when appropriate.
6. Enter check-in time and dates.
7. Enter total guest count and paid room extra-guest names.
8. Select payment mode.
9. For GCash, enter reference number.
10. Review the current quote.
11. Click **Use exact amount** if needed.
12. Submit **Create Booking**.

This workflow requires exact full payment at creation. The Cashier-created payment is recorded as verified.

#### Search and filter bookings

The registry supports:

- reference/guest/contact/email/facility search;
- booking-status filter;
- facility-detail status filter;
- sorting; and
- pagination.

#### View booking details

Click **View** to open the booking workspace. It can show:

- guest and booking information;
- counters and totals;
- facility details;
- amenity requests;
- inspection requests;
- fines; and
- payments.

Actions appear only when the current state permits them.

#### Reschedule a booking

1. Click **Reschedule** on an eligible booking detail.
2. Enter the new check-in date.
3. Save.

The system applies availability and schedule controls before accepting the move.

#### Transfer a facility

1. Click **Transfer**.
2. Select a matching available facility.
3. Save the transfer.

Important controls:

- transfer must remain within the same facility type;
- an upgrade can add the difference to amount due;
- a move to a lower-priced facility is blocked because it would require a refund workflow; and
- only eligible booking states can be modified.

#### Extend an eligible cottage booking

The **Extend** action applies to the approved cottage Day-to-Night/Both rule.

- Only an eligible cottage Day booking can use this extension.
- The system adds the approved additional charge.
- The resulting detail is marked Extended.

### 8.7 GCash Payment Verification

Use **Payments > GCash Verification**.

Public direct-booking GCash submissions begin as Pending.

1. Filter to **Pending**.
2. Find the payment by reference or guest.
3. Click **Review**.
4. Verify the booking, facility, amount, GCash reference, and uploaded proof.
5. If valid, click the verification action and confirm.
6. If invalid, enter a rejection reason and reject the payment.

Verification results:

- Payment -> Verified
- Fully paid booking -> Booked

Rejection results:

- Payment -> Rejected
- Booking/detail -> Payment Rejected
- conflicting facility hold is released according to the availability workflow

Do not treat a Pending payment as verified revenue.

### 8.8 Payment Management

Use **Payments > Record Payment**.

The page has two main areas:

- unpaid payable records; and
- payment history.

Payable types:

- Booking;
- Reservation; and
- Entrance Slip.

To record payment:

1. Search for the unpaid record.
2. Select it.
3. Enter amount paid.
4. Select Cash or GCash.
5. Enter GCash reference if required.
6. Click **Record Payment**.
7. Review the generated receipt.
8. Print the receipt when needed.

Rules:

- amount must be greater than zero;
- overpayment is blocked;
- entrance slips require exact full payment;
- active reservations may receive partial payments;
- existing bookings may receive payments against legitimate current amount due, including amenity/fine/upgrade charges; and
- payment is recorded as Verified when entered by Cashier through this workflow.

Payment history can be filtered by status, mode, target, date, and search text.

### 8.9 Billing Statements

Use **Payments > Billing Statements**.

1. Search or filter the billing records.
2. Review summary counters such as total amount, total due, paid count, and unpaid count.
3. Select a booking record.
4. Review the printable billing statement.
5. Print when needed.

A statement can include:

- facility lines;
- amenity-request charges;
- fines;
- payment lines;
- recorded total;
- total paid; and
- amount due.

The booking's stored total price and amount due remain the authoritative financial totals.

### 8.10 Check-in

Use **Guest Stay > Check-in**.

1. Filter to **Check-in candidates**.
2. Search by booking reference, guest, contact, email, or facility.
3. Use arrival filters when needed.
4. Select an eligible facility detail.
5. Review the confirmation panel.
6. Click **Confirm Check-in**.

A booking detail can be checked in only when the system's payment, booking-status, facility, and schedule conditions are satisfied.

Successful check-in:

- facility detail becomes **Checked-in**;
- physical facility becomes **Occupied**; and
- parent booking becomes Checked-in or Partially Checked-in depending on multi-facility progress.

### 8.11 Cashier-created Amenity Request

Use **Guest Stay > Amenity Requests**.

1. Click the create request action.
2. Select a checked-in booking.
3. Select the checked-in delivery facility.
4. Add one or more rentable amenities and quantities.
5. Submit.

The system:

- calculates the request total;
- adds the charge to booking total and amount due;
- creates the request as **Pending**; and
- sends it to Maintenance for acceptance/delivery.

A Cashier may edit or cancel only a Pending, unassigned request. Once Maintenance accepts it, refund-related reversal is no longer allowed by the normal cancellation workflow.

### 8.12 Checkout Queue

Use **Checkout > Checkout Queue**.

A checked-in facility moves through these practical stages:

1. Needs inspection request
2. Waiting for Maintenance
3. Inspection complete / payment due
4. Ready for checkout
5. Checked-out history

#### Request inspection

1. Select the checked-in booking detail.
2. Click the action to send an inspection request.
3. Wait for Maintenance to accept and complete the inspection.

#### After inspection

- If clean, Maintenance completes the inspection without fines.
- If damage/missing items are found, Maintenance records fines and the booking amount due increases.

#### Collect final balance

If amount due is greater than zero:

1. Open Payment Management.
2. Select the booking.
3. Record the remaining payment without overpaying.
4. Return to Checkout.

#### Complete checkout

Checkout is enabled only when:

- the facility detail is Checked-in;
- inspection request is Completed;
- an inspection result exists; and
- booking amount due is zero.

Click **Confirm Check-out**.

Successful checkout:

- facility detail becomes Checked-out;
- physical facility becomes Available; and
- booking becomes Checked-out or Partially Checked-out based on remaining details.

### 8.13 Cashier Reports

Use **Reports & Work > Reports**.

Available Cashier-oriented report types include:

- My Handled Revenue;
- Booking Summary;
- Cancellation; and
- Available Facilities.

The handled-revenue view is scoped to the applicable Cashier payment activity rather than acting as the full management revenue report.

### 8.14 Cashier Notifications and Action Center

Use these screens to prioritize operational work.

Notifications classify alerts by severity and link the Cashier to the relevant action. The Action Center provides shortcuts and live work items such as payment and checkout tasks.

---

---

## 9. Maintenance Staff Manual

**Demo account for this chapter**

- Username: `maintenance`
- Password: `password`

### 9.1 Maintenance Dashboard, Notifications, and Action Center

After login, use the dashboard for a general operational overview.

Use:

- **Notifications** for live amenity and inspection alerts; and
- **Action Center** for pending amenity requests and facility inspections.

Inspection work appears only after Cashier sends a checkout inspection request.

### 9.2 Accept and Deliver a Cashier-created Amenity Request

Use **Work Queue > Amenity Requests**.

1. Filter to Pending or unassigned requests.
2. Find the request.
3. Click **Accept** and confirm.
4. The request becomes **Delivering** and is assigned to the logged-in maintenance user.
5. Deliver all requested items to the guest.
6. Click **Mark Delivered** and confirm.
7. The request becomes **Delivered**.

Only the assigned maintenance user can confirm delivery.

Payment is not required before delivery. The request charge remains on the booking bill for settlement later.

### 9.3 Create an Amenity Request as Maintenance

The current Maintenance screen also allows Maintenance to create a request for a checked-in stay.

1. Click the create amenity request action.
2. Select a checked-in booking.
3. Select the checked-in booking detail/facility.
4. Add the requested rentable amenities and quantities.
5. Submit.

When Maintenance creates the request:

- it is immediately assigned to the creating maintenance user; and
- it starts in **Delivering** rather than waiting as an unassigned Pending request.

The same billing charge is added to the booking.

### 9.4 Facility Inspections

Use **Work Queue > Facility Inspections**.

The queue can be filtered by:

- request status;
- assignment;
- scheduled departure; and
- search text.

#### Accept an inspection

1. Find an unassigned Pending request.
2. Accept it.
3. The request becomes **In Progress** and is assigned to you.

Another maintenance user cannot take over an active assignment through the normal workflow.

#### Inspect the facility

Review the checklist. It can include:

- inclusive facility amenities; and
- delivered requested amenities.

Enter optional inspection remarks.

#### Clean inspection

If all required items are complete and undamaged:

1. Click **Mark No Damage**.
2. Confirm the action.
3. The inspection/request is completed as clear.

#### Damage or missing items

When a problem is found:

1. Select the checklist item.
2. Select the appropriate fine.
3. Enter quantity.
4. Add the fine.
5. Repeat for other issues.
6. Complete the inspection according to the screen workflow.

The system creates traceable guest fines and increases the booking total and amount due.

Once fines have been recorded, the workflow prevents a contradictory clean/no-damage completion for the same inspection.

---

---

## 10. Security Guard Manual

**Demo account for this chapter**

- Username: `security`
- Password: `password`

### 10.1 Security Dashboard

The dashboard shows entrance-operation information such as:

- slips created today;
- paid entrance slips;
- unpaid entrance slips;
- admitted guests today; and
- paid guest-category breakdown.

The Cashier's payment/admission activity is reflected back into Security's view.

### 10.2 Create an Entrance Slip

Use **Entrance Operations > Create Entrance Slip**.

#### Enter entrance categories

Enter:

- Adults;
- Children; and
- Senior Citizen / PWD.

At least one guest is required.

#### Enter monitoring counts

Enter:

- Male;
- Female; and
- Tourist.

Validation rules:

- Male + Female must equal total entrance-category guests.
- Tourist count cannot exceed total guests.

#### Apply optional discounts

For each category, select an eligible discount and enter the discounted quantity where applicable.

The discounted quantity cannot logically exceed the category quantity.

#### Save the slip

1. Review the calculated total.
2. Save the entrance slip.
3. The slip starts as **Unpaid**.
4. Print the slip.
5. Direct the guest to the Cashier for exact full payment.

The slip total must be greater than zero.

### 10.3 Edit a slip before payment/admission

The Security Guard who created the slip may revise it while all of the following remain true:

- the same Security user is editing it;
- status is Unpaid;
- no payment exists; and
- the guest has not been admitted.

Use the same form to update the counts/discounts and save the revised slip, then print the revised version.

Once any payment exists or the Cashier admits the guest, Security can no longer edit that slip.

---

---

## 11. Complete End-to-End Operational Workflows

This chapter shows how the demo accounts work together.

### 11.1 Walk-in entrance flow

**Security account:** `security` / `password`

1. Create an entrance slip.
2. Confirm the slip is Unpaid.
3. Print the slip.

**Cashier account:** `cashier` / `password`

4. Open Entrance Slips.
5. Select the slip.
6. Record exact full payment.
7. Print receipt if required.
8. Click Admit guest.

Expected end state:

- slip Paid;
- amount due zero;
- admission time/Cashier recorded;
- Security edit locked.

### 11.2 Reservation to guaranteed booking

**Public guest or Cashier**

1. Create an Active reservation.
2. Facility schedule is held according to availability rules.

**Cashier account**

3. Record one or more reservation payments if applicable.
4. When ready to convert, open Reservation Conversion.
5. Pay the exact remaining balance.
6. Convert.

Expected end state:

- reservation Converted;
- one booking created;
- booking Booked;
- booking amount due zero;
- reservation facility schedule ownership transferred to booking.

### 11.3 Direct public GCash booking

**Public guest**

1. Create the direct booking.
2. Pay exact amount through GCash.
3. Submit reference and proof.

Expected interim state:

- payment Pending;
- booking Pending Verification.

**Cashier account**

4. Open GCash Verification.
5. Review proof and target details.
6. Verify.

Expected end state:

- payment Verified;
- booking Booked.

### 11.4 Check-in and amenity delivery

**Cashier account**

1. Open Check-in.
2. Confirm the fully paid eligible booking detail.
3. Facility becomes Occupied.
4. Create a rentable amenity request.

Expected request state: Pending.

**Maintenance account:** `maintenance` / `password`

5. Open Amenity Requests.
6. Accept the request.
7. Request becomes Delivering and assigned to maintenance.
8. Deliver items.
9. Mark Delivered.

Expected financial state:

- amenity charge remains in booking total and amount due until paid.

### 11.5 Maintenance-created amenity request

**Maintenance account**

1. Open Amenity Requests.
2. Create a request against a checked-in booking detail.
3. Add rentable items.
4. Submit.

Expected state:

- request is immediately assigned to the creating maintenance user;
- request starts Delivering;
- charge is added to the booking.

### 11.6 Clean checkout

**Cashier account**

1. Open Checkout Queue.
2. Select checked-in detail.
3. Send inspection request.

**Maintenance account**

4. Open Facility Inspections.
5. Accept request.
6. Inspect checklist.
7. Mark No Damage.

**Cashier account**

8. Return to Checkout.
9. Confirm no remaining balance.
10. Confirm checkout.

Expected end state:

- facility detail Checked-out;
- facility Available.

### 11.7 Damage fine and final settlement

**Cashier account**

1. Send checkout inspection request.

**Maintenance account**

2. Accept request.
3. Select damaged/missing checklist item.
4. Add the appropriate fine and quantity.
5. Complete inspection.

Expected financial state:

- booking total increases;
- amount due increases;
- checkout is blocked while balance remains.

**Cashier account**

6. Record exact remaining payment.
7. Return to Checkout.
8. Confirm checkout.

### 11.8 Management review

**Admin account:** `admin` / `password`

1. Open Management Reports.
2. Generate Revenue for the desired period.
3. Review verified revenue and breakdowns.
4. Generate other operational reports if needed.
5. Open Activity Logs.
6. Locate payment, conversion, fine, or checkout actions from the demonstration.

---

## 12. Core Business Rules Users Must Understand

### 12.1 Reservation is not the same as booking

- **Reservation** = temporary hold that can still have an unpaid balance.
- **Booking** = guaranteed transaction after the required full settlement/verification conditions are met.

### 12.2 Public direct booking is GCash proof-based

There is no automated GCash API settlement in the user workflow. The guest submits proof and the Cashier verifies it manually.

### 12.3 Payment behavior

- Cash and GCash are supported payment modes.
- Public direct booking requires exact full GCash payment.
- Cashier-created initial booking requires exact full payment.
- Active reservations may receive partial payment, but conversion requires exact settlement of any remaining balance.
- Existing bookings may receive later payments for legitimate new amount due, such as amenities, fines, or transfer upgrades.
- Entrance slips require exact full payment.
- Overpayment is rejected.
- Refund processing is not part of the approved workflow.

### 12.4 Room occupancy and extra guests

For Standard Rooms:

- 4 guests are included;
- maximum occupancy is 10;
- guests above 4 are paid extra guests;
- paid extra guest charge is PHP 100.00 each; and
- required extra-guest names must match the computed paid extra count.

Cottage and function-hall capacities are operational recommendations in the current facility-product configuration rather than the room-style strict maximum rule.

### 12.5 Facility schedule policy

- Rooms use overnight date ranges.
- Cottages use Day, Night, or Both schedule slots on the selected date.
- Function halls use a whole-calendar-day schedule.

Availability is calculated from schedule conflicts and transaction state, not only from the visible physical facility status.

### 12.6 Amenity billing

Rentable amenities:

- may be requested only for a checked-in stay;
- are charged to the booking when the request is created;
- may be delivered before payment; and
- remain due until settled.

### 12.7 Inspection gate

Checkout requires a Cashier-initiated inspection and a completed Maintenance result. A Cashier cannot simply skip the inspection step.

### 12.8 Fine behavior

Valid fines increase:

- booking total price; and
- booking amount due.

Checkout remains blocked until the resulting balance is paid.

### 12.9 Historical integrity

The system preserves snapshots of financial values and uses controlled status transitions so later master-data changes do not silently rewrite completed historical transactions.

---

## 13. Status Reference

### Reservation

| Status | Meaning |
|---|---|
| Active | Current reservation hold; may still have amount due |
| Paid | Reservation balance is zero but has not yet been converted |
| Converted | Reservation has been turned into a booking |
| Cancelled | Reservation was cancelled under allowed rules |
| No-show | Expired/unattended reservation released by no-show processing |

### Booking

| Status | Meaning |
|---|---|
| Pending Verification | Public GCash booking awaiting Cashier review |
| Booked | Guaranteed/confirmed booking |
| Payment Rejected | Submitted online payment was rejected |
| Partially Checked-in | Some booking details are checked in |
| Checked-in | All required booking details are checked in |
| Partially Checked-out | Some details are checked out |
| Checked-out | Stay is completed |
| Cancelled | Booking was cancelled where workflow permits |
| Rescheduled / Transferred / Extended | Operational modifications reflected on the booking/detail lifecycle |

### Amenity Request

| Status | Meaning |
|---|---|
| Pending | Waiting for Maintenance acceptance |
| Delivering | Assigned to Maintenance for delivery |
| Delivered | Delivery confirmed |
| Cancelled | Cancelled while still eligible for reversal |

### Inspection Request

| Status | Meaning |
|---|---|
| Pending | Waiting for Maintenance acceptance |
| In Progress | Assigned inspection underway |
| Completed | Inspection result completed |

### Entrance Slip

| State | Meaning |
|---|---|
| Unpaid | Created by Security and still editable by its creator if no payment exists |
| Paid | Exact full payment was recorded by Cashier |
| Admitted | Admission metadata recorded after payment; the slip is locked from Security edits |

---

## 14. Printing and Documents

The system provides print actions where operationally relevant, including:

- entrance slip;
- reservation confirmation;
- booking confirmation;
- payment receipt; and
- billing statement.

When printing:

1. Verify the selected record/reference.
2. Verify amount/status before printing a financial document.
3. Use the dedicated print action when available.
4. For guest confirmation lookup, use the page Print action.
5. Do not treat a Pending GCash record as a verified payment receipt.

---

## 15. Search, Filtering, Sorting, and Pagination

Most data-heavy pages provide a common pattern:

- search box;
- one or more filters;
- sortable column headings;
- row-count selector; and
- pagination.

Recommended use:

1. Start with status filters to narrow operational state.
2. Search by reference number when available.
3. Use guest name/contact only when the reference is unknown.
4. Reset/clear filters when records appear to be missing.
5. Remember that reports can separate official period totals from the currently searched display rows.

---

## 16. Common Error Messages and What to Do

| Situation | Likely Reason | User Action |
|---|---|---|
| Login rejected | Wrong credentials or inactive user | Re-enter credentials; use Forgot Password; contact Admin for inactive status |
| Facility no longer available | Another transaction now conflicts with schedule | Refresh availability and select another unit/date |
| Plan expired/no longer editable | Session-bound facility plan is no longer Draft/valid | Start or refresh the facility plan |
| Online reservation cannot be edited | Not Active or already has verified payment | Contact Cashier |
| Payment exceeds balance | Amount is greater than amount due | Enter an amount not exceeding the balance |
| Entrance-slip partial payment rejected | Entrance slips require exact full payment | Pay the exact amount due |
| Booking creation rejected | Initial booking payment is not exact full amount | Use the current quote / exact amount |
| GCash payment needs reference | GCash mode selected without reference | Enter the GCash reference number |
| Check-in unavailable | Unpaid/invalid booking, unavailable facility, or ineligible status | Resolve payment/status/facility issue first |
| Amenity request unavailable | Booking/facility is not checked in | Complete check-in first |
| Cannot cancel amenity request | Maintenance already accepted/delivered it | Settle through final billing; refund workflow is out of scope |
| Inspection cannot be completed by user | Inspection assigned to another Maintenance user | Use the assigned account or follow proper assignment process |
| Checkout blocked | Inspection incomplete or amount due remains | Complete inspection and settle balance |
| Security cannot edit entrance slip | Different guard, payment exists, slip is Paid, or guest admitted | Cashier/management must handle next operational step; do not alter history |
| Transfer to lower-priced facility blocked | Would require refund | Choose an equal/higher permitted transfer or handle outside unsupported refund flow |

---

## 17. Recommended Daily Operating Checklists

### 17.1 Admin/Manager

- Review dashboard.
- Confirm master-data rates/discounts are current when changes are scheduled.
- Review unusual account status or user-management needs.
- Review management reports.
- Review activity logs when an exception requires investigation.

### 17.2 Cashier

- Check Notifications and Action Center.
- Review pending GCash verifications.
- Review unpaid entrance slips and paid slips awaiting admission.
- Review active reservations needing payment/conversion.
- Review today's check-ins.
- Review checkout queue and inspection status.
- Record final balances before checkout.
- Reconcile payment history/reports for the shift.

### 17.3 Maintenance Staff

- Review Notifications and Action Center.
- Accept unassigned amenity requests.
- Complete assigned deliveries.
- Review pending inspection requests.
- Complete assigned inspections accurately.
- Record fines only when supported by the inspected issue.

### 17.4 Security Guard

- Review today's entrance dashboard.
- Create each entrance slip with accurate headcount.
- Verify Male + Female equals category total.
- Verify Tourist count does not exceed total guests.
- Apply only valid discounts.
- Print the slip before sending the guest to Cashier.
- Revise only before payment/admission.

---

## 18. Demo Data Reference

### 18.1 Payment modes

- Cash
- GCash

### 18.2 Facility types

- Cottage
- Room
- Function Hall

### 18.3 Physical facility counts in the seeded dataset

| Group | Count |
|---|---:|
| Small Cottage | 55 |
| Medium Cottage | 46 |
| Large Cottage | 26 |
| Extra Large Cottage | 5 |
| Standard Room | 12 |
| Function Hall | 2 |
| **Total** | **146** |

### 18.4 Seeded inclusive room items

The seeded room configuration includes items such as:

- Private Shower;
- Television;
- Air Conditioning;
- Beds;
- Pillows;
- Blankets;
- Foot Rug;
- Table;
- Chairs;
- Towels;
- Room Key;
- Aircon Remote;
- Faucet;
- Bidet;
- Vase; and
- TV Remote.

These items can be relevant to maintenance inspection.

---

## 19. Recommended Demonstration Sequence Using the Demo Accounts

For a presentation, evaluation, or turnover session, use this sequence:

1. `security` - create an entrance slip.
2. `cashier` - pay and admit the entrance guest.
3. Public guest - create a reservation or multi-facility plan.
4. `cashier` - receive payment and convert the reservation.
5. Public guest - create a separate direct GCash booking.
6. `cashier` - verify the GCash proof.
7. `cashier` - check in a fully paid booking.
8. `cashier` - create an amenity request.
9. `maintenance` - accept and deliver the amenity.
10. `cashier` - request checkout inspection.
11. `maintenance` - complete a clean inspection or add a damage fine.
12. `cashier` - collect final payment if needed and check out.
13. `admin` - show reports and activity logs.
14. `manager` - demonstrate equivalent management access if required.

This sequence demonstrates the system as one connected resort workflow rather than disconnected screens.

---

## 20. Operational Boundaries

Users should be aware of the following system boundaries:

- No refund workflow is included.
- There is no automatic GCash API verification.
- Public staff registration is not included.
- Confirmed booking modification is staff-controlled rather than a public guest self-service workflow.
- Facility preset configuration is predefined in the current operational UI; Admin creates physical facilities from those presets.
- Master and historical financial records should be updated/deactivated through supported workflows rather than manually deleted from the database.

---

## 21. Support and Escalation Guidance

When a user encounters a problem:

1. Record the reference number involved.
2. Record the current status shown on screen.
3. Note the role/account performing the action.
4. Capture the validation/error message.
5. Do not manually change database values to force the workflow forward.
6. For financial discrepancies, stop before recording another payment.
7. For availability discrepancies, refresh/re-run availability rather than assigning a conflicting facility.
8. For audit investigation, Admin/Manager should review Activity Logs.

For demo support, the seeded accounts listed at the beginning of this manual provide a consistent role-by-role reproduction path.

---

## 22. Final User Checklist

Before considering a guest transaction complete, verify the relevant final state:

- Entrance guest: slip Paid and admission recorded.
- Reservation: Active/Paid/Converted/Cancelled/No-show status is correct.
- Booking before arrival: Booked and correct amount due.
- Check-in: facility detail Checked-in and physical facility Occupied.
- Amenity request: correct delivery status and charge included.
- Checkout inspection: Completed with correct clean/fine result.
- Final payment: amount due is zero.
- Checkout: booking detail Checked-out and physical facility Available.
- Financial review: payment is Verified before treating it as received revenue.

---

## Appendix A. Demo Credentials Quick Reference

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `password` |
| Manager | `manager` | `password` |
| Cashier | `cashier` | `password` |
| Maintenance Staff | `maintenance` | `password` |
| Security Guard | `security` | `password` |

## Appendix B. Main Navigation Quick Reference

### Admin / Manager

- Dashboard
- Master Data
  - Facilities
  - Amenities
- Financial Configuration
  - Entrance Fees
  - Discounts
  - Fines
- Reports
  - Management Reports
  - Activity Logs
- Administration
  - Staff Users

### Cashier

- Dashboard
- Front Desk
  - Reservations
  - Reservation Conversion
  - Bookings
- Payments
  - GCash Verification
  - Record Payment
  - Entrance Slips
  - Billing Statements
- Guest Stay
  - Check-in
  - Amenity Requests
- Checkout
  - Checkout Queue
- Reports & Work
  - Reports
  - Notifications
  - Action Center

### Maintenance Staff

- Dashboard
- Work Queue
  - Amenity Requests
  - Facility Inspections
- Workspace
  - Notifications
  - Action Center

### Security Guard

- Dashboard
- Entrance Operations
  - Create Entrance Slip

### Public Guest

- Home
- Plan Facilities
- Reserve
- Book
- Manage Reservation
- Confirmation Lookup

## Appendix C. Report Catalog

| Role | Report | Primary Purpose |
|---|---|---|
| Admin/Manager | Revenue | Review verified received revenue for the selected period |
| Admin/Manager | Booking Summary | Review booking activity and status distribution/details |
| Admin/Manager | Cancellation | Review cancelled transactions |
| Admin/Manager | Damaged Amenities | Review damage/fine-related operational data |
| Admin/Manager | Available Facilities | Review currently available facility information |
| Admin/Manager | Facility Utilization | Review facility usage/utilization |
| Admin/Manager | Tourism Enterprise Monthly | Produce tourism-oriented monthly operational output |
| Cashier | My Handled Revenue | Review revenue handled by the applicable Cashier scope |
| Cashier | Booking Summary | Review operational booking records |
| Cashier | Cancellation | Review cancellation records |
| Cashier | Available Facilities | Review facility availability |

## Appendix D. Manual Baseline

This manual was prepared against the current project source on the `UX-updates` branch at commit:

`872c4629519aa8c6960660953c76338a1089ac4d`

If later commits change labels, routes, business rules, or workflow states, update this manual alongside the application so the operating instructions remain synchronized with the deployed build.
