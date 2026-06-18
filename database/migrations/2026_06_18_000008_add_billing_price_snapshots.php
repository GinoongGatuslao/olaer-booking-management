<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_booking_details', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_booking_details', 'base_price')) {
                $table->decimal('base_price', 10, 2)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('tbl_booking_details', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->nullable()->after('base_price');
            }

            if (! Schema::hasColumn('tbl_booking_details', 'extra_guest_fee')) {
                $table->decimal('extra_guest_fee', 10, 2)->nullable()->after('discount_amount');
            }

            if (! Schema::hasColumn('tbl_booking_details', 'line_total')) {
                $table->decimal('line_total', 10, 2)->nullable()->after('extra_guest_fee');
            }
        });

        Schema::table('tbl_amenity_request_details', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_amenity_request_details', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable()->after('amenity_quantity');
            }

            if (! Schema::hasColumn('tbl_amenity_request_details', 'line_total')) {
                $table->decimal('line_total', 10, 2)->nullable()->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_amenity_request_details', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_amenity_request_details', 'line_total')) {
                $table->dropColumn('line_total');
            }

            if (Schema::hasColumn('tbl_amenity_request_details', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });

        Schema::table('tbl_booking_details', function (Blueprint $table) {
            foreach (['line_total', 'extra_guest_fee', 'discount_amount', 'base_price'] as $column) {
                if (Schema::hasColumn('tbl_booking_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
