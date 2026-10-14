<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JarekFrPublication
{
    public const CONFIRM = 'PUBLISH-JAREK-GEARBOXES-GPSWISS-FR';

    public function __construct(private JarekFrPresentation $presentation) {}

    public function dryRun(int $actorId): array
    {
        $this->ready();
        $report = $this->inspect($this->presentation->rate());
        $id = (string) Str::uuid();
        DB::table('jarek_fr_publication_reports')->insert([
            'id' => $id, 'actor_id' => $actorId, 'snapshot_hash' => $report['snapshot_hash'],
            'preview' => json_encode($report, JSON_THROW_ON_ERROR), 'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['report_id' => $id, 'confirm_token' => self::CONFIRM] + $report;
    }

    public function report(string $id, int $actorId): array
    {
        $this->ready();
        $row = DB::table('jarek_fr_publication_reports')->where('id', $id)->where('actor_id', $actorId)->first();
        abort_unless($row, 404);

        return ['report_id' => $id, 'apply_report' => $row->apply_report ? json_decode($row->apply_report, true) : null]
            + json_decode($row->preview, true, 512, JSON_THROW_ON_ERROR);
    }

    public function apply(string $id, int $actorId, string $confirm): array
    {
        if ($confirm !== self::CONFIRM) {
            $this->block('confirm', 'Wpisz dokładny token potwierdzenia publikacji FR.');
        }
        $this->ready();
        // Never contact NBP while holding publication/source database locks.
        $existing = DB::table('jarek_fr_publication_reports')->where('id', $id)->where('actor_id', $actorId)->first();
        if (! $existing) {
            $this->block('report_id', 'Najpierw wykonaj własny dry-run.');
        }
        if ($existing->apply_report) {
            return ['replayed' => true] + json_decode($existing->apply_report, true);
        }
        if (now()->greaterThanOrEqualTo($existing->expires_at)) {
            $this->block('report_id', 'Dry-run wygasł. Wykonaj nowy.');
        }
        $rate = $this->presentation->rate();

        return DB::transaction(function () use ($id, $actorId, $rate): array {
            $row = DB::table('jarek_fr_publication_reports')->where('id', $id)->where('actor_id', $actorId)->lockForUpdate()->first();
            if ($row->apply_report) {
                return ['replayed' => true] + json_decode($row->apply_report, true);
            }
            if (now()->greaterThanOrEqualTo($row->expires_at)) {
                $this->block('report_id', 'Dry-run wygasł. Wykonaj nowy.');
            }
            $fresh = $this->inspect($rate, true);
            if (! hash_equals($row->snapshot_hash, $fresh['snapshot_hash'])) {
                $this->block('report_id', 'Dane lub kurs zmieniły się. Sprawdź nowy dry-run przed apply.');
            }
            if ($fresh['summary']['no_nbp_rate_count'] > 0) {
                $this->block('rate', 'Brak aktualnego kursu NBP; publikacja zablokowana.');
            }
            $created = $activated = $already = 0;
            $ids = [];
            foreach ($fresh['products'] as $item) {
                if ($item['blockers'] !== []) {
                    continue;
                }
                $product = JarekFrProduct::query()->where('jarek_gearbox_id', $item['jarek_gearbox_id'])->first();
                if (! $product) {
                    $product = JarekFrProduct::create(['jarek_gearbox_id' => $item['jarek_gearbox_id'], 'is_active' => true, 'published_at' => now()]);
                    $created++;
                } elseif (! $product->is_active) {
                    $product->update(['is_active' => true, 'published_at' => now()]);
                    $activated++;
                } else {
                    $already++;
                }
                $ids[] = $product->id;
            }
            $result = ['ok' => true, 'report_id' => $id, 'storefront_code' => 'gpswiss_fr',
                'created_count' => $created, 'activated_count' => $activated, 'already_active_count' => $already,
                'blocked_count' => $fresh['summary']['blocked_count'], 'product_ids' => $ids,
                'marketplace_write' => false, 'source_stock_write' => false, 'parts_created' => 0,
                'applied_at' => now()->toIso8601String()];
            DB::table('jarek_fr_publication_reports')->where('id', $id)->update(['apply_report' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return ['replayed' => false] + $result;
        });
    }

    private function inspect(array $rate, bool $lock = false): array
    {
        $query = JarekGearbox::query()->with('translations')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $products = $query->get();
        $existing = JarekFrProduct::query()->get()->keyBy('jarek_gearbox_id');
        $summary = array_fill_keys(['total_jarek', 'would_publish_count', 'blocked_count', 'no_price_count', 'no_images_count',
            'no_nbp_rate_count', 'would_use_fallback_translation_count', 'would_use_fr_translation_count', 'delivery_130_count',
            'pl_visibility_risk_count', 'already_active_count'], 0);
        $summary['total_jarek'] = $products->count();
        $fingerprint = [];
        $rows = $products->map(function ($gearbox) use ($rate, $existing, &$summary, &$fingerprint): array {
            $p = $this->presentation->forGearbox($gearbox, $rate);
            $active = (bool) $existing->get($gearbox->id)?->is_active;
            foreach (['no_price', 'no_images', 'no_nbp_rate'] as $reason) {
                if (in_array($reason, $p['blockers'], true)) {
                    $summary[$reason.'_count']++;
                }
            }
            if ($p['can_buy']) {
                $summary[$active ? 'already_active_count' : 'would_publish_count']++;
                $summary[$p['uses_fallback_translation'] ? 'would_use_fallback_translation_count' : 'would_use_fr_translation_count']++;
                $summary['delivery_130_count']++;
            } else {
                $summary['blocked_count']++;
            }
            $fingerprint[] = [$gearbox->id, (string) $gearbox->price, $gearbox->currency, $gearbox->translationSourceHash(),
                $p['title'], $p['description'], $p['images'], $p['blockers'], $active];

            return ['jarek_gearbox_id' => $gearbox->id, 'title' => $p['title'], 'sku' => $p['sku'], 'price_source_pln' => $p['base_price_pln'],
                'price_after_markup_pln' => $p['price_after_markup_pln'], 'price_eur' => $p['cents'] !== null ? $p['cents'] / 100 : null,
                'images_count' => count($p['images']), 'quantity_fr' => 1, 'delivery_eur' => 130, 'only_storefront' => 'gpswiss_fr',
                'already_active' => $active, 'uses_fallback_translation' => $p['uses_fallback_translation'], 'blockers' => $p['blockers']];
        })->all();

        return ['dry_run' => true, 'marketplace_write' => false, 'source_write' => false, 'summary' => $summary,
            'nbp_rate' => $rate, 'products' => $rows, 'snapshot_hash' => hash('sha256', json_encode([
                $fingerprint, $rate['ok'] ?? false, $rate['rate'] ?? null, $rate['effective_date'] ?? null, $rate['table_no'] ?? null,
            ], JSON_THROW_ON_ERROR))];
    }

    private function ready(): void
    {
        if (! Schema::hasTable('jarek_fr_products') || ! Schema::hasTable('jarek_fr_publication_reports') || ! Schema::hasColumn('stripe_fr_attempts', 'jarek_fr_product_id') || ! Schema::hasColumn('order_items', 'jarek_fr_product_id')) {
            $this->block('schema', 'Wymagana migracja ekspozycji Jarka FR.');
        }
    }

    private function block(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
