<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string, callable(Blueprint): void>
     */
    private function missingSafeColumns(): array
    {
        return [
            'description' => fn (Blueprint $table): mixed => $table->longText('description')->nullable(),
            'plain_description' => fn (Blueprint $table): mixed => $table->longText('plain_description')->nullable(),
            'parameters' => fn (Blueprint $table): mixed => $table->json('parameters')->nullable(),
            'main_image_url' => fn (Blueprint $table): mixed => $table->text('main_image_url')->nullable(),
            'images' => fn (Blueprint $table): mixed => $table->json('images')->nullable(),
            'category_id' => fn (Blueprint $table): mixed => $table->string('category_id')->nullable()->index(),
            'category_name' => fn (Blueprint $table): mixed => $table->string('category_name')->nullable(),
            'category_path' => fn (Blueprint $table): mixed => $table->json('category_path')->nullable(),
            'category_payload' => fn (Blueprint $table): mixed => $table->json('category_payload')->nullable(),
            'raw_payload' => fn (Blueprint $table): mixed => $table->json('raw_payload')->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('jarek_gearboxes')) {
            return;
        }

        foreach ($this->missingSafeColumns() as $column => $addColumn) {
            if (! Schema::hasColumn('jarek_gearboxes', $column)) {
                Schema::table('jarek_gearboxes', function (Blueprint $table) use ($addColumn): void {
                    $addColumn($table);
                });
            }
        }
    }

    public function down(): void
    {
        // Defensive migration: intentionally do not drop or rename columns/data.
    }
};
