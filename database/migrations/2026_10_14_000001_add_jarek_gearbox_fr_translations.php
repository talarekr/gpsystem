<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarek_gearbox_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jarek_gearbox_id')->constrained('jarek_gearboxes')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->string('provider')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('status')->default('missing')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['jarek_gearbox_id', 'locale']);
        });
        Schema::table('storefront_fr_translation_runs', function (Blueprint $table): void {
            $table->string('scope')->default('catalog')->index();
        });
    }

    public function down(): void
    {
        if (DB::table('storefront_fr_translation_runs')->where('scope', 'jarek_gearboxes')->exists()) {
            throw new RuntimeException('Cannot drop translation scope while Jarek checkpoints exist.');
        }
        Schema::dropIfExists('jarek_gearbox_translations');
        Schema::table('storefront_fr_translation_runs', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
