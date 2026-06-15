<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            $table->string('source_system')->nullable()->index()->after('id');
            $table->string('external_id')->nullable()->index()->after('source_system');
            $table->json('legacy_payload')->nullable()->after('vehicle_location');
        });
        Schema::table('parts', function (Blueprint $table): void {
            $table->string('source_system')->nullable()->index()->after('id');
            $table->string('external_id')->nullable()->index()->after('source_system');
            $table->string('legacy_url')->nullable()->after('slug');
            $table->string('legacy_slug')->nullable()->after('legacy_url');
            $table->json('legacy_payload')->nullable()->after('vehicle_snapshot');
        });
        Schema::table('part_images', function (Blueprint $table): void {
            $table->string('source_system')->nullable()->index()->after('id');
            $table->string('external_id')->nullable()->index()->after('source_system');
            $table->text('alt_text')->nullable()->after('path');
        });
        Schema::table('part_categories', function (Blueprint $table): void {
            $table->string('source_system')->nullable()->index()->after('id');
            $table->string('external_id')->nullable()->index()->after('source_system');
            $table->string('category_path')->nullable()->after('slug');
            $table->json('legacy_payload')->nullable()->after('category_path');
        });
    }

    public function down(): void
    {
        Schema::table('part_categories', fn (Blueprint $table) => $table->dropColumn(['source_system','external_id','category_path','legacy_payload']));
        Schema::table('part_images', fn (Blueprint $table) => $table->dropColumn(['source_system','external_id','alt_text']));
        Schema::table('parts', fn (Blueprint $table) => $table->dropColumn(['source_system','external_id','legacy_url','legacy_slug','legacy_payload']));
        Schema::table('cars', fn (Blueprint $table) => $table->dropColumn(['source_system','external_id','legacy_payload']));
    }
};
