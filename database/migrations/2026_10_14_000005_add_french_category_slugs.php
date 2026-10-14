<?php

use App\Services\Storefront\FrenchCategoryUrls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('category_translations')) {
            return;
        }
        foreach (['slug' => 'string', 'slug_name_hash' => 'string', 'slug_history' => 'json'] as $column => $type) {
            if (! Schema::hasColumn('category_translations', $column)) {
                Schema::table('category_translations', fn (Blueprint $table) => $table->{$type}($column)->nullable());
            }
        }
        // Derived metadata only. No provider, source PL category edits or timestamp/content changes.
        DB::table('category_translations')->where('locale', 'fr')->whereIn('status', ['translated', 'reviewed'])
            ->orderBy('id')->chunkById(200, function ($translations): void {
                foreach ($translations as $row) {
                    if (trim((string) $row->name) === '') {
                        continue;
                    }
                    $slug = FrenchCategoryUrls::generatedSlug($row->name, $row->category_id);
                    $hash = FrenchCategoryUrls::nameHash($row->name);
                    if ($row->slug === $slug && $row->slug_name_hash === $hash) {
                        continue;
                    }
                    $history = json_decode($row->slug_history ?? '[]', true) ?: [];
                    if ($row->slug && $row->slug !== $slug) {
                        $history[] = $row->slug;
                    }
                    DB::table('category_translations')->where('id', $row->id)->update(['slug' => $slug, 'slug_name_hash' => $hash,
                        'slug_history' => json_encode(array_values(array_unique($history)), JSON_THROW_ON_ERROR)]);
                }
            });
        if (! Schema::hasIndex('category_translations', ['locale', 'slug'], 'unique')) {
            $name = 'ct_locale_slug_uq';
            $suffix = 0;
            while (Schema::hasIndex('category_translations', $name)) {
                $name = 'ct_locale_slug_uq_'.++$suffix;
            }
            Schema::table('category_translations', fn (Blueprint $table) => $table->unique(['locale', 'slug'], $name));
        }
    }

    public function down(): void
    {
        // Preserve URL aliases and derived metadata on rollback; old code safely ignores these columns.
    }
};
