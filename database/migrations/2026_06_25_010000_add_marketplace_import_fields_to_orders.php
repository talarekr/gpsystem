<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'marketplace')) $table->string('marketplace')->nullable()->after('customer_id')->index();
            if (! Schema::hasColumn('orders', 'marketplace_order_id')) $table->string('marketplace_order_id')->nullable()->after('marketplace');
            if (! Schema::hasColumn('orders', 'marketplace_status')) $table->string('marketplace_status')->nullable()->after('marketplace_order_id')->index();
            if (! Schema::hasColumn('orders', 'ordered_at')) $table->timestamp('ordered_at')->nullable()->after('marketplace_status')->index();
            if (! Schema::hasColumn('orders', 'payment_status')) $table->string('payment_status')->nullable()->after('shipping_total');
            if (! Schema::hasColumn('orders', 'delivery_method')) $table->string('delivery_method')->nullable()->after('payment_status');
            if (! Schema::hasColumn('orders', 'invoice_data')) $table->json('invoice_data')->nullable()->after('country');
            if (! Schema::hasColumn('orders', 'raw_payload')) $table->json('raw_payload')->nullable()->after('invoice_data');
            if (! Schema::hasColumn('orders', 'imported_at')) $table->timestamp('imported_at')->nullable()->after('raw_payload');
            if (! Schema::hasColumn('orders', 'test_import')) $table->boolean('test_import')->default(false)->after('imported_at')->index();
            if (! Schema::hasColumn('orders', 'source_batch')) $table->string('source_batch')->nullable()->after('test_import')->index();
            $table->unique(['marketplace', 'marketplace_order_id'], 'orders_marketplace_order_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'marketplace')) $table->string('marketplace')->nullable()->after('order_id')->index();
            if (! Schema::hasColumn('order_items', 'marketplace_order_id')) $table->string('marketplace_order_id')->nullable()->after('marketplace');
            if (! Schema::hasColumn('order_items', 'marketplace_item_id')) $table->string('marketplace_item_id')->nullable()->after('marketplace_order_id');
            if (! Schema::hasColumn('order_items', 'offer_id')) $table->string('offer_id')->nullable()->after('marketplace_item_id');
            if (! Schema::hasColumn('order_items', 'external_product_id')) $table->string('external_product_id')->nullable()->after('sku');
            if (! Schema::hasColumn('order_items', 'currency')) $table->string('currency', 3)->nullable()->after('line_total');
            if (! Schema::hasColumn('order_items', 'raw_payload')) $table->json('raw_payload')->nullable()->after('meta');
            $table->unique(['marketplace', 'marketplace_order_id', 'marketplace_item_id'], 'order_items_marketplace_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_items_marketplace_item_unique');
            $table->dropColumn(['marketplace', 'marketplace_order_id', 'marketplace_item_id', 'offer_id', 'external_product_id', 'currency', 'raw_payload']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_marketplace_order_unique');
            $table->dropColumn(['marketplace', 'marketplace_order_id', 'marketplace_status', 'ordered_at', 'payment_status', 'delivery_method', 'invoice_data', 'raw_payload', 'imported_at', 'test_import', 'source_batch']);
        });
    }
};
