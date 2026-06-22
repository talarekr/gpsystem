<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('parts', 'ovoko_price')) {
                $table->decimal('ovoko_price', 12, 2)->nullable()->after('allegro_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (Schema::hasColumn('parts', 'ovoko_price')) {
                $table->dropColumn('ovoko_price');
            }
        });
    }
};
