<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('parts', 'weight_kg')) {
                $table->decimal('weight_kg', 8, 3)->nullable()->after('storage_location_id');
            }

            if (! Schema::hasColumn('parts', 'length_cm')) {
                $table->decimal('length_cm', 8, 2)->nullable()->after('weight_kg');
            }

            if (! Schema::hasColumn('parts', 'width_cm')) {
                $table->decimal('width_cm', 8, 2)->nullable()->after('length_cm');
            }

            if (! Schema::hasColumn('parts', 'height_cm')) {
                $table->decimal('height_cm', 8, 2)->nullable()->after('width_cm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            foreach (['height_cm', 'width_cm', 'length_cm', 'weight_kg'] as $column) {
                if (Schema::hasColumn('parts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
