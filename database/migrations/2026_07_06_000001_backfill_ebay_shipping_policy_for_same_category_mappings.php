<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_category_mappings')) return;

        $columns = ['external_category_id', 'shipping_group', 'fulfillment_policy_id'];
        foreach ($columns as $column) if (! Schema::hasColumn('marketplace_category_mappings', $column)) return;

        foreach (['ebay_de', 'ebay_fr'] as $channel) {
            $sourceRows = DB::table('marketplace_category_mappings')
                ->select(['external_category_id', 'shipping_group', 'fulfillment_policy_id'])
                ->where('channel', $channel)
                ->whereNotNull('external_category_id')
                ->whereNotNull('shipping_group')
                ->whereNotNull('fulfillment_policy_id')
                ->orderBy('id')
                ->get()
                ->unique('external_category_id');

            foreach ($sourceRows as $source) {
                DB::table('marketplace_category_mappings')
                    ->where('channel', $channel)
                    ->where('external_category_id', $source->external_category_id)
                    ->where(function ($query): void {
                        $query->whereNull('shipping_group')->orWhereNull('fulfillment_policy_id');
                    })
                    ->update([
                        'shipping_group' => $source->shipping_group,
                        'fulfillment_policy_id' => $source->fulfillment_policy_id,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Data-only safety backfill. Do not clear operator-maintained shipping policy mappings on rollback.
    }
};
