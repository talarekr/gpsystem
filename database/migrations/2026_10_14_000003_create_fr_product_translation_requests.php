<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_fr_product_translation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source_hash', 64);
            $table->string('status', 30)->index();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_fr_product_translation_requests');
    }
};
