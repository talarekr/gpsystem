<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->string('sale_source')->nullable()->index()->after('status');
            $table->timestamp('sold_at')->nullable()->index()->after('sale_source');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn(['sale_source', 'sold_at']);
        });
    }
};
