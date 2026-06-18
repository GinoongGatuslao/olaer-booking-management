<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The discount documentation stores start and end with time, not date only.
     */
    public function up(): void
    {
        Schema::table('tbl_discount', function (Blueprint $table) {
            $table->dateTime('discount_start')->nullable()->change();
            $table->dateTime('discount_end')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_discount', function (Blueprint $table) {
            $table->date('discount_start')->nullable()->change();
            $table->date('discount_end')->nullable()->change();
        });
    }
};
