<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table): void {
                foreach (['order_id','carrier','service_code','shipment_status','tracking_number','carrier_shipment_id','label_path','label_format'] as $column) {
                    if (! Schema::hasColumn('shipments', $column)) {
                        match ($column) {
                            'order_id' => $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete(),
                            default => $table->string($column)->nullable(),
                        };
                    }
                }
                foreach (['sender_snapshot','receiver_snapshot','parcel_snapshot','request_payload','response_payload'] as $column) {
                    if (! Schema::hasColumn('shipments', $column)) {
                        $table->json($column)->nullable();
                    }
                }
                if (! Schema::hasColumn('shipments', 'test_mode')) {
                    $table->boolean('test_mode')->default(true)->index();
                }
                if (! Schema::hasColumn('shipments', 'created_at')) {
                    $table->timestamps();
                }
            });
            return;
        }

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('carrier')->nullable()->index();
            $table->string('service_code')->nullable();
            $table->string('shipment_status')->default('draft')->index();
            $table->string('tracking_number')->nullable()->index();
            $table->string('carrier_shipment_id')->nullable()->index();
            $table->string('label_path')->nullable();
            $table->string('label_format')->nullable();
            $table->json('sender_snapshot')->nullable();
            $table->json('receiver_snapshot')->nullable();
            $table->json('parcel_snapshot')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->boolean('test_mode')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
