<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->json('part_snapshot')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PLN');
            $table->string('payment_method');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('marketplace_sync_status')->default('pending')->index();
            $table->json('marketplace_sync_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_sales');
    }
};
