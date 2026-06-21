<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_accounts') || ! Schema::hasColumn('marketplace_accounts', 'api_credentials')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE marketplace_accounts MODIFY api_credentials LONGTEXT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE marketplace_accounts ALTER COLUMN api_credentials TYPE TEXT');
            DB::statement('ALTER TABLE marketplace_accounts ALTER COLUMN api_credentials DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketplace_accounts') || ! Schema::hasColumn('marketplace_accounts', 'api_credentials')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE marketplace_accounts MODIFY api_credentials JSON NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE marketplace_accounts ALTER COLUMN api_credentials TYPE JSON USING api_credentials::json');
            DB::statement('ALTER TABLE marketplace_accounts ALTER COLUMN api_credentials DROP NOT NULL');
        }
    }
};
