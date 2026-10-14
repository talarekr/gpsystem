<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarek_fr_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jarek_gearbox_id')->unique()->constrained('jarek_gearboxes')->restrictOnDelete();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('jarek_fr_publication_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('snapshot_hash', 64);
            $table->json('preview');
            $table->json('apply_report')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('stripe_fr_attempts', function (Blueprint $table): void {
            $table->foreignId('part_id')->nullable()->change();
            $table->foreignId('jarek_fr_product_id')->nullable()->constrained('jarek_fr_products')->restrictOnDelete();
            $table->index(['jarek_fr_product_id', 'status', 'expires_at']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('jarek_fr_product_id')->nullable()->constrained('jarek_fr_products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('stripe_fr_attempts')->whereNotNull('jarek_fr_product_id')->exists()
            || DB::table('order_items')->whereNotNull('jarek_fr_product_id')->exists()) {
            throw new RuntimeException('Jarek order/payment references must be retained; rollback requires a separate data plan.');
        }
        Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('jarek_fr_product_id'));
        Schema::table('stripe_fr_attempts', function (Blueprint $table): void {
            $table->dropIndex(['jarek_fr_product_id', 'status', 'expires_at']);
            $table->dropConstrainedForeignId('jarek_fr_product_id');
            $table->foreignId('part_id')->nullable(false)->change();
        });
        Schema::dropIfExists('jarek_fr_publication_reports');
        Schema::dropIfExists('jarek_fr_products');
    }
};
