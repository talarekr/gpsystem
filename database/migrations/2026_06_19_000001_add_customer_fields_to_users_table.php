<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone', 40)->nullable()->after('last_name');
            $table->string('tax_id', 32)->nullable()->after('phone');
            $table->string('company_name')->nullable()->after('tax_id');
            $table->string('google_id')->nullable()->unique()->after('company_name');
        });
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['first_name','last_name','phone','tax_id','company_name','google_id']);
        });
    }
};
