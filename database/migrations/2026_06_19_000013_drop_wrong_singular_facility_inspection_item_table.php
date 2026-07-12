<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A previous package accidentally created this singular table name.
        // The correct table is tbl_facility_inspection_items.
        Schema::dropIfExists('tbl_facility_inspection_item');
    }

    public function down(): void
    {
        // No rollback needed. This table was accidental and unused.
    }
};
