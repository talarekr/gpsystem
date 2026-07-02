<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ovoko_stock_sync_runs')) return;

        Schema::table('ovoko_stock_sync_runs', function (Blueprint $table): void {
            foreach ([
                'available_on_ovoko_count',
                'not_available_on_ovoko_count',
                'availability_unknown_count',
                'local_for_sale_count',
                'local_sold_count',
                'already_correct_count',
                'should_mark_for_sale_count',
                'should_mark_sold_count',
            ] as $column) {
                if (! Schema::hasColumn('ovoko_stock_sync_runs', $column)) {
                    $table->unsignedInteger($column)->default(0)->after('failed_count');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ovoko_stock_sync_runs')) return;

        Schema::table('ovoko_stock_sync_runs', function (Blueprint $table): void {
            foreach ([
                'available_on_ovoko_count',
                'not_available_on_ovoko_count',
                'availability_unknown_count',
                'local_for_sale_count',
                'local_sold_count',
                'already_correct_count',
                'should_mark_for_sale_count',
                'should_mark_sold_count',
            ] as $column) {
                if (Schema::hasColumn('ovoko_stock_sync_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
