# Olaer Spring Resort Laravel Migrations

Source: `WEB-BASED BOOKING MANAGEMENT WITH BILLING SYSTEM FOR OLAER SPRING RESORT.pdf`, data dictionary/final relation around document pages 646-673.

## Files

1. `2026_06_17_000001_create_identity_tables.php`
   - `tbl_address`
   - `tbl_role`
   - `tbl_user`
   - `tbl_guest`

2. `2026_06_17_000002_create_master_data_tables.php`
   - `tbl_amenity_name`
   - `tbl_amenity`
   - `tbl_entrance_fee`
   - `tbl_damage_type`
   - `tbl_fine`
   - `tbl_facility_type`
   - `tbl_facility`
   - `tbl_facility_amenities`
   - `tbl_facility_price`
   - `tbl_discount`

3. `2026_06_17_000003_create_transaction_tables.php`
   - `tbl_reservation`
   - `tbl_reservation_details`
   - `tbl_reservation_extra_guests`
   - `tbl_entrance_slip`
   - `tbl_entrance_slip_details`
   - `tbl_booking`
   - `tbl_booking_extra_guests`
   - `tbl_booking_details`
   - `tbl_guest_fine`
   - `tbl_amenity_request`
   - `tbl_amenity_request_details`
   - `tbl_mode_of_payment`
   - `tbl_payment`

## Practical fixes added

- `contact_no` is stored as string, not int, because Philippine numbers can start with `0`.
- `password` is 255 chars, not 50, because Laravel stores hashed passwords.
- `tbl_entrance_slip` uses `created_by_user_id` and `handled_by_user_id` because the document lists `user_id` twice for different meanings.
- `tbl_discount` includes `discount_start` and `discount_end` because the final relation and module requirements mention discount validity.
- `tbl_payment` includes nullable `entrance_slip_id` and `proof_of_payment_path` because the system accepts entrance slip payments and GCash proof uploads.
- Extra guest tables use surrogate IDs because the documentation does not provide primary keys for them.
- Laravel auth compatibility fields were added to `tbl_user`: `email_verified_at`, `remember_token`, and timestamps.
