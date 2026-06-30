<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->string('allegro_shipping_rate_name')->nullable()->after('ebay_price');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('allegro_shipping_rate_name');
        });
    }
};
