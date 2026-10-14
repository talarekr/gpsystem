<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureTable('storefront_jarek_image_runs', $this->runColumns(), function (Blueprint $table): void {
            $table->foreign('actor_id', 'sji_runs_actor_fk')->references('id')->on('users');
        });
        $this->ensureIndex('storefront_jarek_image_runs', ['run_id'], 'sji_runs_uuid_uq', true);
        $this->ensureIndex('storefront_jarek_image_runs', ['actor_id', 'id'], 'sji_runs_actor_idx');

        $this->ensureTable('storefront_jarek_image_items', $this->itemColumns(), function (Blueprint $table): void {
            $table->foreign('run_id', 'sji_items_run_fk')->references('run_id')->on('storefront_jarek_image_runs')->cascadeOnDelete();
        });
        $this->ensureIndex('storefront_jarek_image_items', ['run_id', 'source_id', 'image_number'], 'sji_items_image_uq', true);
        $this->ensureIndex('storefront_jarek_image_items', ['run_id', 'status', 'id'], 'sji_items_status_idx');
    }

    private function ensureTable(string $name, array $columns, Closure $constraints): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) use ($columns, $constraints): void {
                foreach ($columns as $define) {
                    $define($table);
                }
                $constraints($table);
            });

            return;
        }
        // MySQL DDL commits per statement. A failed deploy can leave tables, columns or indexes behind.
        // Missing columns on existing rows remain NULL; never invent/reset a checkpoint or source data.
        foreach ($columns as $column => $define) {
            if (! Schema::hasColumn($name, $column)) {
                Schema::table($name, function (Blueprint $table) use ($column, $define): void {
                    $definition = $define($table);
                    if ($column !== 'id') {
                        $definition->nullable();
                    }
                });
            }
        }
        // Preserve existing FK constraints. Do not impose new FKs on unvalidated legacy rows.
    }

    private function ensureIndex(string $table, array $columns, string $name, bool $unique = false): void
    {
        $kind = $unique ? 'unique' : null;
        if (Schema::hasIndex($table, $columns, $kind)) {
            return;
        }
        // Also tolerate an older differently shaped index occupying our preferred name.
        $suffix = 0;
        $candidate = $name;
        while (Schema::hasIndex($table, $candidate)) {
            $candidate = $name.'_'.++$suffix;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $candidate, $unique): void {
            $unique ? $blueprint->unique($columns, $candidate) : $blueprint->index($columns, $candidate);
        });
    }

    private function runColumns(): array
    {
        return [
            'id' => fn (Blueprint $t) => $t->id(),
            'run_id' => fn (Blueprint $t) => $t->uuid('run_id'),
            'actor_id' => fn (Blueprint $t) => $t->unsignedBigInteger('actor_id'),
            'status' => fn (Blueprint $t) => $t->string('status'),
            'phase' => fn (Blueprint $t) => $t->string('phase'),
            'checkpoint' => fn (Blueprint $t) => $t->json('checkpoint'),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];
    }

    private function itemColumns(): array
    {
        return [
            'id' => fn (Blueprint $t) => $t->id(),
            'run_id' => fn (Blueprint $t) => $t->uuid('run_id'),
            'source_id' => fn (Blueprint $t) => $t->unsignedBigInteger('source_id'),
            'image_number' => fn (Blueprint $t) => $t->unsignedTinyInteger('image_number')->default(0),
            'source_name' => fn (Blueprint $t) => $t->string('source_name', 300)->nullable(),
            'local_url' => fn (Blueprint $t) => $t->string('local_url', 2048)->nullable(),
            'fingerprint' => fn (Blueprint $t) => $t->string('fingerprint')->nullable(),
            'classification' => fn (Blueprint $t) => $t->string('classification'),
            'status' => fn (Blueprint $t) => $t->string('status'),
            'result_code' => fn (Blueprint $t) => $t->string('result_code')->nullable(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];
    }

    public function down(): void
    {
        // Deliberately non-destructive: these tables may predate this migration's ledger entry.
        // Rollback cannot establish ownership and must preserve checkpoints and per-image results.
    }
};
