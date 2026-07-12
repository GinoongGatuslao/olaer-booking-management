<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The correct table name from the previous working inspection checklist module is plural:
        // tbl_facility_inspection_items
        if (! Schema::hasTable('tbl_facility_inspection_items')) {
            Schema::create('tbl_facility_inspection_items', function (Blueprint $table): void {
                $table->id('facility_inspection_item_id');
                $table->foreignId('facility_inspection_id')
                    ->constrained('tbl_facility_inspection', 'facility_inspection_id')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->string('item_source', 40); // facility_amenity / amenity_request
                $table->unsignedBigInteger('source_id');
                $table->foreignId('amenity_id')
                    ->constrained('tbl_amenity', 'amenity_id')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->unsignedInteger('expected_quantity')->default(1);
                $table->string('condition_status', 40)->default('Complete');
                $table->foreignId('fine_id')
                    ->nullable()
                    ->constrained('tbl_fine', 'fine_id')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
                $table->unsignedInteger('fine_quantity')->default(0);
                $table->decimal('total_charge', 10, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['item_source', 'source_id'], 'inspection_items_source_index');
                $table->unique(
                    ['facility_inspection_id', 'item_source', 'source_id', 'fine_id'],
                    'inspection_item_fine_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_inspection_items');
    }
};
