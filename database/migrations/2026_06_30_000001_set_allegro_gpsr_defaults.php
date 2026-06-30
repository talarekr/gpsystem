<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_accounts')) return;

        $account = DB::table('marketplace_accounts')->where('code', 'allegro_main')->first();
        if (! $account) return;

        $settings = json_decode((string) ($account->api_settings ?? '[]'), true);
        if (! is_array($settings)) $settings = [];
        if (! isset($settings['gpsr']) || ! is_array($settings['gpsr'])) $settings['gpsr'] = [];

        $settings['gpsr']['responsibleProducer'] ??= [
            'type' => 'NAME',
            'name' => 'GREGOR swiss GRZEGORZ PACIOREK',
        ];
        $settings['gpsr']['safetyInformation'] ??= [
            'type' => 'TEXT',
            'description' => 'Część używana pochodząca z demontażu pojazdu. Montaż powinien zostać wykonany przez wykwalifikowany warsztat lub osobę posiadającą odpowiednią wiedzę techniczną. Przed montażem należy porównać numer części i zgodność z pojazdem. Produkt nie jest zabawką.',
        ];

        DB::table('marketplace_accounts')
            ->where('id', $account->id)
            ->update(['api_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public function down(): void
    {
        // Intentionally keep operator-provided GPSR settings; removing them could break Allegro readiness.
    }
};
