<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('parts', 'additional_part_codes')) {
                $table->json('additional_part_codes')->nullable()->after('part_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            if (Schema::hasColumn('parts', 'additional_part_codes')) {
                $table->dropColumn('additional_part_codes');
            }
        });
    }
};
