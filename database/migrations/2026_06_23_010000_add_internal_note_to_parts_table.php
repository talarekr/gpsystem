<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parts') && ! Schema::hasColumn('parts', 'internal_note')) {
            Schema::table('parts', function (Blueprint $table): void {
                $table->text('internal_note')->nullable()->after('condition_notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('parts') && Schema::hasColumn('parts', 'internal_note')) {
            Schema::table('parts', function (Blueprint $table): void {
                $table->dropColumn('internal_note');
            });
        }
    }
};
