<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_sync_logs', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('part_id')->constrained('orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->after('order_id')->constrained('shipments')->nullOnDelete();
            $table->string('http_status')->nullable()->after('status')->index();
            $table->unsignedInteger('duration_ms')->nullable()->after('message');
            $table->string('request_id')->nullable()->after('duration_ms')->index();
            $table->string('external_id')->nullable()->after('request_id')->index();
            $table->string('tracking_number')->nullable()->after('external_id')->index();
            $table->index('created_at');
            $table->index(['marketplace', 'status', 'created_at']);
            $table->index(['order_id', 'created_at']);
            $table->index(['shipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_sync_logs', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['shipment_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['marketplace', 'status', 'created_at']);
            $table->dropIndex(['order_id', 'created_at']);
            $table->dropIndex(['shipment_id', 'created_at']);
            $table->dropIndex(['http_status']);
            $table->dropIndex(['request_id']);
            $table->dropIndex(['external_id']);
            $table->dropIndex(['tracking_number']);
            $table->dropColumn(['order_id', 'shipment_id', 'http_status', 'duration_ms', 'request_id', 'external_id', 'tracking_number']);
        });
    }
};
