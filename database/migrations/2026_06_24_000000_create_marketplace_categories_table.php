<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_categories')) {
            Schema::create('marketplace_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('channel');
                $table->string('external_category_id');
                $table->string('parent_external_category_id')->nullable();
                $table->unsignedInteger('level')->default(0);
                $table->string('name');
                $table->text('full_path')->nullable();
                $table->json('raw_payload')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->unique(['channel', 'external_category_id']);
                $table->index(['channel', 'parent_external_category_id']);
                $table->index(['channel', 'level']);
                $table->index('active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_categories');
    }
};
