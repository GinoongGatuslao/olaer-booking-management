<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $supportsAddedForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        Schema::table('tbl_booking_details', function (Blueprint $table) use ($supportsAddedForeignKeys) {
            $table->foreignId('facility_product_id')->nullable()->after('facility_id');

            if ($supportsAddedForeignKeys) {
                $table->foreign('facility_product_id')
                    ->references('facility_product_id')
                    ->on('tbl_facility_product')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            }
            $table->unsignedInteger('guest_count')->nullable()->after('facility_product_id');
            $table->string('capacity_policy', 40)->nullable()->after('guest_count');
            $table->unsignedSmallInteger('included_guest_count_snapshot')->nullable()->after('capacity_policy');
            $table->unsignedSmallInteger('strict_maximum_snapshot')->nullable()->after('included_guest_count_snapshot');
            $table->unsignedSmallInteger('suggested_minimum_snapshot')->nullable()->after('strict_maximum_snapshot');
            $table->unsignedSmallInteger('suggested_maximum_snapshot')->nullable()->after('suggested_minimum_snapshot');
            $table->string('schedule_policy', 30)->nullable()->after('suggested_maximum_snapshot');
            $table->string('rate_code', 30)->nullable()->after('schedule_policy');
            $table->decimal('unit_rate', 10, 2)->nullable()->after('rate_code');
            $table->decimal('discount_rate', 7, 6)->nullable()->after('discount_id');
            $table->unique(['booking_id', 'booking_details_id'], 'uq_booking_detail_parent');
        });

        Schema::table('tbl_reservation_details', function (Blueprint $table) use ($supportsAddedForeignKeys) {
            $table->foreignId('facility_product_id')->nullable()->after('facility_id');

            if ($supportsAddedForeignKeys) {
                $table->foreign('facility_product_id')
                    ->references('facility_product_id')
                    ->on('tbl_facility_product')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            }
            $table->unsignedInteger('guest_count')->nullable()->after('facility_product_id');
            $table->string('capacity_policy', 40)->nullable()->after('guest_count');
            $table->unsignedSmallInteger('included_guest_count_snapshot')->nullable()->after('capacity_policy');
            $table->unsignedSmallInteger('strict_maximum_snapshot')->nullable()->after('included_guest_count_snapshot');
            $table->unsignedSmallInteger('suggested_minimum_snapshot')->nullable()->after('strict_maximum_snapshot');
            $table->unsignedSmallInteger('suggested_maximum_snapshot')->nullable()->after('suggested_minimum_snapshot');
            $table->string('schedule_policy', 30)->nullable()->after('suggested_maximum_snapshot');
            $table->string('rate_code', 30)->nullable()->after('schedule_policy');
            $table->decimal('unit_rate', 10, 2)->nullable()->after('rate_code');
            $table->decimal('base_price', 10, 2)->nullable()->after('discount_id');
            $table->decimal('discount_rate', 7, 6)->nullable()->after('base_price');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('discount_rate');
            $table->decimal('extra_guest_fee', 10, 2)->nullable()->after('discount_amount');
            $table->decimal('line_total', 10, 2)->nullable()->after('extra_guest_fee');
            $table->unique(['reservation_id', 'reservation_details_id'], 'uq_reservation_detail_parent');
        });

        Schema::table('tbl_booking_extra_guests', function (Blueprint $table) use ($supportsAddedForeignKeys) {
            $table->foreignId('booking_details_id')->nullable()->after('booking_id');

            if ($supportsAddedForeignKeys) {
                $table->foreign(
                    ['booking_id', 'booking_details_id'],
                    'fk_booking_extra_guest_detail_owner',
                )->references(['booking_id', 'booking_details_id'])
                    ->on('tbl_booking_details')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            }
        });

        Schema::table('tbl_reservation_extra_guests', function (Blueprint $table) use ($supportsAddedForeignKeys) {
            $table->foreignId('reservation_details_id')->nullable()->after('reservation_id');

            if ($supportsAddedForeignKeys) {
                $table->foreign(
                    ['reservation_id', 'reservation_details_id'],
                    'fk_reservation_extra_guest_detail_owner',
                )->references(['reservation_id', 'reservation_details_id'])
                    ->on('tbl_reservation_details')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $dropsConstraintsByName = Schema::getConnection()->getDriverName() !== 'sqlite';

        if (
            $dropsConstraintsByName
            && $this->hasForeignKey(
                'tbl_reservation_extra_guests',
                'fk_reservation_extra_guest_detail_owner',
            )
        ) {
            Schema::table('tbl_reservation_extra_guests', function (Blueprint $table) {
                $table->dropForeign('fk_reservation_extra_guest_detail_owner');
            });
        }
        if ($dropsConstraintsByName) {
            $this->restoreParentIndex(
                'tbl_reservation_extra_guests',
                'reservation_id',
                'tbl_reservation_extra_guests_reservation_id_foreign',
            );
            $this->dropIndexIfPresent(
                'tbl_reservation_extra_guests',
                'fk_reservation_extra_guest_detail_owner',
            );
        }
        if (Schema::hasColumn('tbl_reservation_extra_guests', 'reservation_details_id')) {
            Schema::table('tbl_reservation_extra_guests', function (Blueprint $table) {
                $table->dropColumn('reservation_details_id');
            });
        }

        if (
            $dropsConstraintsByName
            && $this->hasForeignKey(
                'tbl_booking_extra_guests',
                'fk_booking_extra_guest_detail_owner',
            )
        ) {
            Schema::table('tbl_booking_extra_guests', function (Blueprint $table) {
                $table->dropForeign('fk_booking_extra_guest_detail_owner');
            });
        }
        if ($dropsConstraintsByName) {
            $this->restoreParentIndex(
                'tbl_booking_extra_guests',
                'booking_id',
                'tbl_booking_extra_guests_booking_id_foreign',
            );
            $this->dropIndexIfPresent(
                'tbl_booking_extra_guests',
                'fk_booking_extra_guest_detail_owner',
            );
        }
        if (Schema::hasColumn('tbl_booking_extra_guests', 'booking_details_id')) {
            Schema::table('tbl_booking_extra_guests', function (Blueprint $table) {
                $table->dropColumn('booking_details_id');
            });
        }

        if (
            $dropsConstraintsByName
            && $this->hasForeignKey(
                'tbl_reservation_details',
                'tbl_reservation_details_facility_product_id_foreign',
            )
        ) {
            Schema::table('tbl_reservation_details', function (Blueprint $table) {
                $table->dropForeign(['facility_product_id']);
            });
        }
        if ($dropsConstraintsByName) {
            $this->restoreParentIndex(
                'tbl_reservation_details',
                'reservation_id',
                'tbl_reservation_details_reservation_id_foreign',
            );
        }
        $this->dropIndexIfPresent(
            'tbl_reservation_details',
            'uq_reservation_detail_parent',
            true,
        );
        foreach ([
            'facility_product_id',
            'guest_count',
            'capacity_policy',
            'included_guest_count_snapshot',
            'strict_maximum_snapshot',
            'suggested_minimum_snapshot',
            'suggested_maximum_snapshot',
            'schedule_policy',
            'rate_code',
            'unit_rate',
            'base_price',
            'discount_rate',
            'discount_amount',
            'extra_guest_fee',
            'line_total',
        ] as $column) {
            if (Schema::hasColumn('tbl_reservation_details', $column)) {
                Schema::table('tbl_reservation_details', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if (
            $dropsConstraintsByName
            && $this->hasForeignKey(
                'tbl_booking_details',
                'tbl_booking_details_facility_product_id_foreign',
            )
        ) {
            Schema::table('tbl_booking_details', function (Blueprint $table) {
                $table->dropForeign(['facility_product_id']);
            });
        }
        if ($dropsConstraintsByName) {
            $this->restoreParentIndex(
                'tbl_booking_details',
                'booking_id',
                'tbl_booking_details_booking_id_foreign',
            );
        }
        $this->dropIndexIfPresent(
            'tbl_booking_details',
            'uq_booking_detail_parent',
            true,
        );
        foreach ([
            'facility_product_id',
            'guest_count',
            'capacity_policy',
            'included_guest_count_snapshot',
            'strict_maximum_snapshot',
            'suggested_minimum_snapshot',
            'suggested_maximum_snapshot',
            'schedule_policy',
            'rate_code',
            'unit_rate',
            'discount_rate',
        ] as $column) {
            if (Schema::hasColumn('tbl_booking_details', $column)) {
                Schema::table('tbl_booking_details', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function restoreParentIndex(
        string $tableName,
        string $column,
        string $indexName,
    ): void {
        if ($this->hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $indexName) {
            $table->index($column, $indexName);
        });
    }

    private function dropIndexIfPresent(
        string $tableName,
        string $indexName,
        bool $unique = false,
    ): void {
        if (! $this->hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName, $unique) {
            if ($unique) {
                $table->dropUnique($indexName);

                return;
            }

            $table->dropIndex($indexName);
        });
    }

    private function hasForeignKey(string $tableName, string $foreignKeyName): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $foreignKeyName) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
