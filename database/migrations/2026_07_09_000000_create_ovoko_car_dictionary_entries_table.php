<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovoko_car_dictionary_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('dictionary')->index();
            $table->string('ovoko_id');
            $table->string('name')->nullable()->index();
            $table->string('ovoko_brand_id')->default('')->index();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['dictionary', 'ovoko_id', 'ovoko_brand_id'], 'ovoko_car_dictionary_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ovoko_car_dictionary_entries');
    }
};
