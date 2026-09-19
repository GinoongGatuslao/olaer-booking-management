<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tbl_facility')
            ->whereNull('facility_number')
            ->orderBy('facility_id')
            ->eachById(function (object $facility): void {
                DB::table('tbl_facility')
                    ->where('facility_id', $facility->facility_id)
                    ->update([
                        'facility_number' => 'FAC-'.str_pad((string) $facility->facility_id, 4, '0', STR_PAD_LEFT),
                    ]);
            }, column: 'facility_id');
    }

    public function down(): void
    {
        DB::table('tbl_facility')
            ->where('facility_number', 'like', 'FAC-%')
            ->update(['facility_number' => null]);
    }
};
