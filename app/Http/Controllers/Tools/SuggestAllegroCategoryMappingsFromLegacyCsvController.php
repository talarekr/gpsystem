<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use SplFileObject;
use Throwable;

class SuggestAllegroCategoryMappingsFromLegacyCsvController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $sampleLimit = max(0, min(500, (int) $request->query('sample_limit', 100)));
        $recordLimit = max(1, min(10000, (int) $request->query('record_limit', 5000)));
        $offset = max(0, (int) $request->query('offset', 0));
        $batchSize = $request->query('batch_size') !== null ? max(1, min(1000, (int) $request->query('batch_size'))) : null;
        $minProducts = max(1, (int) $request->query('min_products', 1));
        $onlyMissingAllegro = $request->boolean('only_missing_allegro', true);
        $onlyPublic = $request->boolean('only_public', true);
        $leafOnly = $request->boolean('leaf_only', true);
        $categoryId = $request->query('category_id');
        $confidenceFilter = $request->query('confidence');

        $csvPath = $this->resolveCsvPath();
        $diagnostics = $this->emptyDiagnostics($csvPath);

        if (! $csvPath) {
            return response()->json($this->criticalPayload(
                $diagnostics,
                'Legacy Woo/Allegro CSV was not found in storage/app/imports.'
            ) + [
                'possible_match_fields_checked' => $this->possibleMatchFields(),
            ]);
        }

        try {
            $allegroNames = $this->allegroCategoryNames();
            $groups = [];
            $stats = ['total_legacy_rows' => 0, 'rows_with_allegro_category_id' => 0, 'matched_products_count' => 0, 'unmatched_products_count' => 0];

            foreach ($this->readRows($csvPath, $diagnostics) as $row) {
                if ($stats['total_legacy_rows'] >= $recordLimit) {
                    $this->addDiagnosticInfo($diagnostics, 'record_limit_reached', "Stopped after {$recordLimit} CSV rows.");
                    break;
                }

                $rowIndex = $stats['total_legacy_rows'];
                $stats['total_legacy_rows']++;
                if ($rowIndex < $offset) {
                    $diagnostics['csv_rows_skipped_by_offset']++;
                    continue;
                }
                if ($batchSize !== null && $diagnostics['csv_rows_read'] >= $batchSize) {
                    break;
                }

                $diagnostics['csv_rows_read']++;

                $wooProductId = trim((string) ($row['woo_product_id'] ?? ''));
                $csvSku = $this->extractCsvSku($row);
                $allegroOfferId = $this->extractAllegroOfferId($row, $diagnostics);

                $this->addSample($diagnostics['sample_woo_product_ids'], $wooProductId);
                $this->addSample($diagnostics['sample_allegro_offer_ids'], $allegroOfferId);
                if ($csvSku === '') {
                    $diagnostics['sku_empty_count']++;
                } else {
                    $this->addSample($diagnostics['sample_skus'], $csvSku);
                }

                if ($wooProductId === '') {
                    $diagnostics['missing_woo_product_id_count']++;
                }
                if ($allegroOfferId === '') {
                    $diagnostics['missing_allegro_offer_id_count']++;
                }

                $allegroCategoryId = $this->extractAllegroCategoryId($row, $diagnostics);
                if ($allegroCategoryId !== null && $allegroCategoryId !== '') {
                    $stats['rows_with_allegro_category_id']++;
                } else {
                    $diagnostics['missing_allegro_category_id_count']++;
                }

                try {
                    $part = $this->findPart($wooProductId, $csvSku, $allegroOfferId, $onlyPublic, $diagnostics);
                } catch (Throwable $e) {
                    $part = null;
                    $this->addDiagnosticError($diagnostics, 'product_lookup_failed', $e->getMessage(), ['woo_product_id' => $wooProductId]);
                }

                $rejectedReason = $this->partRejectedReason($part, $onlyPublic, $leafOnly, $onlyMissingAllegro);
                if ($rejectedReason !== null) {
                    $stats['unmatched_products_count']++;
                    $diagnostics['unmatched_products_count']++;
                    $this->annotateLastMatchAttempt($diagnostics, $allegroOfferId, $rejectedReason);
                    $this->annotateOfferTableSample($diagnostics, $allegroOfferId, false, $rejectedReason);
                    if ($this->lastAttemptIsOfferTable($diagnostics, $allegroOfferId)) $this->incrementOfferTableRejectionCounter($diagnostics, $rejectedReason);
                    continue;
                }

                if ($categoryId !== null && (string) $part->category_id !== (string) $categoryId) {
                    $this->annotateLastMatchAttempt($diagnostics, $allegroOfferId, 'category_filter_mismatch');
                    $this->annotateOfferTableSample($diagnostics, $allegroOfferId, false, 'category_filter_mismatch');
                    continue;
                }

                $this->annotateLastMatchAttempt($diagnostics, $allegroOfferId, null);
                $this->annotateOfferTableSample($diagnostics, $allegroOfferId, true, null);
                if ($this->lastAttemptIsOfferTable($diagnostics, $allegroOfferId)) $diagnostics['offer_table_accepted_count']++;

                $stats['matched_products_count']++;
                $diagnostics['matched_products_count']++;
                $key = (string) $part->category_id;
                $categoryName = trim((string) ($part->category_name ?? ''));
                $categoryPath = trim((string) ($part->category_path ?? ''));
                if ($categoryName === '' && $categoryPath === '') {
                    $this->addDiagnosticError($diagnostics, 'missing_category_name_or_path', 'Local category has neither path nor public name.', ['local_category_id' => (int) $part->category_id]);
                }

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'local_category_id' => (int) $part->category_id,
                        'local_category_name' => $categoryName !== '' ? $categoryName : null,
                        'category_path' => $categoryPath !== '' ? $categoryPath : ($categoryName !== '' ? $categoryName : null),
                        'matched_products_count' => 0,
                        'unmatched_products_count' => 0,
                        'total_legacy_rows' => 0,
                        'counts' => [],
                        'sample_products' => [],
                    ];
                }
                $groups[$key]['matched_products_count']++;
                $groups[$key]['total_legacy_rows']++;
                if ($allegroCategoryId !== null && $allegroCategoryId !== '') {
                    $groups[$key]['counts'][$allegroCategoryId] = ($groups[$key]['counts'][$allegroCategoryId] ?? 0) + 1;
                }
                if (count($groups[$key]['sample_products']) < $sampleLimit) {
                    $groups[$key]['sample_products'][] = ['local_product_id'=>(int)$part->id,'woo_product_id'=>$wooProductId,'csv_sku'=>$csvSku !== '' ? $csvSku : null,'sku'=>$part->sku,'product_name'=>$part->name,'allegro_offer_id'=>$allegroOfferId,'allegro_category_id'=>$allegroCategoryId];
                }
            }

            $suggestions = $this->buildSuggestions($groups, $allegroNames, $minProducts, $confidenceFilter);

            return response()->json($this->flags() + [
                'ok' => true,
                'dry_run' => $request->boolean('dry_run', true),
                'csv_path' => $csvPath,
                'parameters' => compact('sampleLimit','recordLimit','offset','batchSize','minProducts','onlyMissingAllegro','onlyPublic','leafOnly','categoryId','confidenceFilter'),
                'possible_match_fields_checked' => $this->possibleMatchFields(),
                'matched_products_count' => $stats['matched_products_count'],
                'unmatched_products_count' => $stats['unmatched_products_count'],
                'total_legacy_rows' => $stats['total_legacy_rows'],
                'rows_with_allegro_category_id' => $stats['rows_with_allegro_category_id'],
                'suggested_mapping_count' => count($suggestions),
                'suggested_mappings' => $suggestions,
                'diagnostics' => $diagnostics,
            ]);
        } catch (Throwable $e) {
            $this->addDiagnosticError($diagnostics, 'critical_error', $e->getMessage());

            return response()->json($this->criticalPayload($diagnostics, $e->getMessage()));
        }
    }




    public function runner(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response('Invalid diagnostics token.', 403);
        }

        if ($request->boolean('diagnostics')) {
            return response()->json($this->runnerDiagnosticsPayload(true));
        }

        try {
            return response($this->runnerHtml((string) $request->query('token')), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (Throwable $e) {
            return response($this->runnerErrorHtml($e), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }
    }

    public function batch(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        try {
            $offset = max(0, (int) $request->query('offset', 0));
            $batchSize = max(1, min(1000, (int) $request->query('batch_size', 100)));
            $recordLimit = max(1, min(10000, (int) $request->query('record_limit', 5000)));
            $confirm = $request->boolean('confirm', false);
            $result = $this->evaluateApplyRequest($request, ['offset' => $offset, 'batch_size' => $batchSize, 'record_limit' => $recordLimit]);
            $processedRows = (int) data_get($result, 'diagnostics.csv_rows_read', 0);
            $nextOffset = min($recordLimit, $offset + $processedRows);
            $done = $nextOffset >= $recordLimit || $processedRows < $batchSize;

            return response()->json($this->applyFlags($confirm, (int) ($result['created_count'] ?? 0) > 0) + $result + [
                'ok' => true,
                'offset' => $offset,
                'batch_size' => $batchSize,
                'processed_rows' => $processedRows,
                'next_offset' => $nextOffset,
                'done' => $done,
                'batch_number' => intdiv($offset, $batchSize) + 1,
            ]);
        } catch (Throwable $e) {
            return response()->json($this->applyFlags($request->boolean('confirm', false), false) + [
                'ok' => false,
                'dry_run' => ! $request->boolean('confirm', false),
                'error' => $e->getMessage(),
                'errors_count' => 1,
                'items' => [],
            ]);
        }
    }

    public function apply(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        try {
            return response()->json($this->evaluateApplyRequest($request));
        } catch (Throwable $e) {
            return response()->json($this->applyFlags($request->boolean('confirm', false), false) + [
                'ok' => false,
                'dry_run' => ! $request->boolean('confirm', false),
                'error' => $e->getMessage(),
                'errors_count' => 1,
                'items' => [],
            ]);
        }
    }

    private function evaluateApplyRequest(Request $request, array $overrides = []): array
    {
        $confirm = $request->boolean('confirm', false);
        $dryRun = ! $confirm;
        $onlyMissingAllegro = $request->boolean('only_missing_allegro', true);
        $excludeUncategorized = $request->boolean('exclude_uncategorized', true);
        $minMatchedProducts = max(1, (int) $request->query('min_matched_products', 1));
        $minSuggestedShare = max(0, min(1, (float) $request->query('min_suggested_share', 0.9)));
        $confidenceFilter = $request->query('confidence');

        $internalQuery = array_merge($request->query(), $overrides);
        $internalQuery['only_missing_allegro'] = 0;
        $internalQuery['min_products'] = 1;
        unset($internalQuery['confidence']);
        $internalRequest = Request::create('/tools/suggest-allegro-category-mappings-from-legacy-csv', 'GET', $internalQuery);
        $payload = $this->__invoke($internalRequest)->getData(true);

        if (! ($payload['ok'] ?? false)) {
            return $this->applyFlags($confirm, false) + $payload;
        }

        $items = [];
        foreach (($payload['suggested_mappings'] ?? []) as $mapping) {
            $item = $this->applyItem($mapping, $onlyMissingAllegro, $excludeUncategorized, $minMatchedProducts, $minSuggestedShare, $confidenceFilter);

            if ($confirm && $item['action'] === 'would_create') {
                if (! $this->hasAllegroMapping((int) $item['local_category_id'])) {
                    $this->createAllegroMapping($item);
                    $item['action'] = 'created';
                } else {
                    $item['action'] = 'skipped_existing_mapping';
                }
            }

            $item['status'] = $item['action'] === 'created' ? 'created' : (str_starts_with((string) $item['action'], 'skipped_') ? 'skipped' : 'pending');
            $item['reason'] = $item['action'];
            $items[] = $item;
        }

        $counts = $this->actionCounts($items);

        return $this->applyFlags($confirm, $counts['created_count'] > 0) + [
            'ok' => true,
            'dry_run' => $dryRun,
            'csv_path' => $payload['csv_path'] ?? null,
            'parameters' => [
                'only_missing_allegro' => $onlyMissingAllegro,
                'only_public' => $request->boolean('only_public', true),
                'leaf_only' => $request->boolean('leaf_only', true),
                'exclude_uncategorized' => $excludeUncategorized,
                'min_matched_products' => $minMatchedProducts,
                'min_suggested_share' => $minSuggestedShare,
                'confidence' => $confidenceFilter,
            ],
            'matched_products_count' => $payload['matched_products_count'] ?? 0,
            'unmatched_products_count' => $payload['unmatched_products_count'] ?? 0,
            'suggested_mapping_count' => $payload['suggested_mapping_count'] ?? 0,
            'would_create_count' => $counts['would_create_count'],
            'created_count' => $counts['created_count'],
            'skipped_count' => $counts['skipped_count'],
            'skipped_uncategorized_count' => $counts['skipped_uncategorized_count'],
            'skipped_existing_mapping_count' => $counts['skipped_existing_mapping_count'],
            'skipped_low_confidence_count' => $counts['skipped_low_confidence_count'],
            'skipped_low_share_count' => $counts['skipped_low_share_count'],
            'errors_count' => $this->diagnosticErrorsCount($payload['diagnostics'] ?? []),
            'items' => $items,
            'diagnostics' => $payload['diagnostics'] ?? [],
        ];
    }

    private function applyItem(array $mapping, bool $onlyMissingAllegro, bool $excludeUncategorized, int $minMatchedProducts, float $minSuggestedShare, ?string $confidenceFilter): array
    {
        $item = [
            'local_category_id' => $mapping['local_category_id'] ?? null,
            'local_category_name' => $mapping['local_category_name'] ?? null,
            'category_path' => $mapping['category_path'] ?? null,
            'suggested_allegro_category_id' => $mapping['suggested_allegro_category_id'] ?? null,
            'suggested_allegro_category_name' => $mapping['suggested_allegro_category_name'] ?? null,
            'matched_products_count' => $mapping['matched_products_count'] ?? 0,
            'suggested_count' => $mapping['suggested_count'] ?? 0,
            'suggested_share' => $mapping['suggested_share'] ?? 0.0,
            'confidence' => $mapping['confidence'] ?? null,
            'action' => 'would_create',
        ];

        if (($item['suggested_allegro_category_id'] ?? null) === null || (string) $item['suggested_allegro_category_id'] === '') $item['action'] = 'skipped_no_allegro_category';
        elseif ($excludeUncategorized && ((int) $item['local_category_id'] === 20 || (string) $item['local_category_name'] === 'Bez kategorii')) $item['action'] = 'skipped_uncategorized';
        elseif ($onlyMissingAllegro && $this->hasAllegroMapping((int) $item['local_category_id'])) $item['action'] = 'skipped_existing_mapping';
        elseif ((int) $item['matched_products_count'] < $minMatchedProducts) $item['action'] = 'skipped_low_confidence';
        elseif ((float) $item['suggested_share'] < $minSuggestedShare) $item['action'] = 'skipped_low_share';
        elseif ($confidenceFilter !== null && $confidenceFilter !== '' && (string) $item['confidence'] !== (string) $confidenceFilter) $item['action'] = 'skipped_low_confidence';

        return $item;
    }

    private function createAllegroMapping(array $item): void
    {
        $now = now();
        $row = [
            'local_category_id' => (int) $item['local_category_id'],
            'channel' => 'allegro',
            'external_category_id' => (string) $item['suggested_allegro_category_id'],
            'external_category_name' => $item['suggested_allegro_category_name'],
            'local_category_name' => $item['local_category_name'],
            'local_category_path' => $item['category_path'],
            'source' => 'legacy_csv_offer_id_match',
            'confidence' => $item['confidence'],
            'metadata' => json_encode(['matched_products_count' => $item['matched_products_count'], 'suggested_count' => $item['suggested_count'], 'suggested_share' => $item['suggested_share']]),
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $row = array_filter($row, fn ($value, $column) => Schema::hasColumn('marketplace_category_mappings', $column), ARRAY_FILTER_USE_BOTH);
        DB::table('marketplace_category_mappings')->insert($row);
    }


    private function actionCounts(array $items): array
    {
        $counts = [
            'would_create_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'skipped_uncategorized_count' => 0,
            'skipped_existing_mapping_count' => 0,
            'skipped_low_confidence_count' => 0,
            'skipped_low_share_count' => 0,
        ];

        foreach ($items as $item) {
            $action = (string) ($item['action'] ?? '');

            if ($action === 'would_create') {
                $counts['would_create_count']++;
                continue;
            }

            if ($action === 'created') {
                $counts['created_count']++;
                continue;
            }

            if (str_starts_with($action, 'skipped_')) {
                $counts['skipped_count']++;

                $specificCountKey = $action.'_count';
                if (array_key_exists($specificCountKey, $counts)) {
                    $counts[$specificCountKey]++;
                }
            }
        }

        return $counts;
    }

    private function runnerDiagnosticsPayload(bool $ok): array
    {
        return [
            'ok' => $ok,
            'route_loaded' => Route::has('tools.allegro-legacy-category-mapping-runner'),
            'ui_method_exists' => method_exists($this, 'runner'),
            'batch_route_url' => url('/tools/run-allegro-legacy-category-mapping-batch'),
            'default_parameters' => $this->runnerDefaultParameters(),
        ];
    }

    private function runnerDefaultParameters(): array
    {
        return [
            'batch_size' => 100,
            'offset' => 0,
            'record_limit' => 5000,
            'sample_limit' => 100,
            'only_missing_allegro' => true,
            'only_public' => true,
            'leaf_only' => true,
            'exclude_uncategorized' => true,
            'min_suggested_share' => 0.9,
            'min_matched_products' => 1,
            'confidence' => null,
        ];
    }

    private function runnerErrorHtml(Throwable $e): string
    {
        $payload = [
            'ok' => false,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
        ];
        $json = htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Allegro legacy category mapping runner error</title><style>body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#111}pre{background:#111;color:#eee;padding:12px;overflow:auto}</style></head><body>
<h1>Allegro legacy category mapping runner</h1>
<p>UI runner nie mógł zostać wyrenderowany. Laravel 500 został zastąpiony defensywną stroną błędu.</p>
<pre>{$json}</pre>
</body></html>
HTML;
    }

    private function runnerHtml(string $token): string
    {
        $tokenJson = json_encode($token, JSON_THROW_ON_ERROR);
        $html = <<<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Allegro legacy category mapping runner</title><style>body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#111}label{display:block;margin:8px 0}input{margin-left:8px}button{margin:8px 6px 8px 0;padding:8px 12px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.card{border:1px solid #ddd;border-radius:6px;padding:8px;background:#fafafa}progress{width:100%;height:24px}table{border-collapse:collapse;width:100%;margin-top:16px}th,td{border:1px solid #ddd;padding:6px;text-align:left}th{background:#f3f3f3}pre{background:#111;color:#eee;padding:12px;overflow:auto}</style></head><body>
<h1>Allegro legacy category mapping runner</h1>
<p>Local-only runner. UI renderuje tylko HTML/JS; CSV jest przetwarzany dopiero przez endpoint batch po kliknięciu start.</p>
<section><h2>Parametry</h2>
<label>batch_size <input id="batch_size" type="number" value="100" min="1" max="1000"></label>
<label>offset/start <input id="offset" type="number" value="0" min="0"></label>
<label>record_limit <input id="record_limit" type="number" value="5000" min="1" max="10000"></label>
<label>sample_limit <input id="sample_limit" type="number" value="100" min="0" max="500"></label>
<label><input id="only_missing_allegro" type="checkbox" checked> only_missing_allegro</label>
<label><input id="only_public" type="checkbox" checked> only_public</label>
<label><input id="leaf_only" type="checkbox" checked> leaf_only</label>
<label><input id="exclude_uncategorized" type="checkbox" checked> exclude_uncategorized</label>
<label>min_suggested_share <input id="min_suggested_share" type="number" value="0.9" min="0" max="1" step="0.01"></label>
<label>min_matched_products <input id="min_matched_products" type="number" value="1" min="1"></label>
<label>confidence <input id="confidence" placeholder="opcjonalnie, np. high"></label>
<button onclick="start(false)">Start dry-run</button><button onclick="start(true)">Start confirm local mappings</button><button onclick="stopRunner()">Stop</button></section>
<h2>Postęp</h2><progress id="progress" value="0" max="100"></progress><p id="progressText">processed_rows: <span id="processedRows">0</span> / <span id="recordLimit">5000</span>, batch: <span id="batchNumber">0</span></p><div class="cards"><div class="card"><b>created_count</b><br><span id="createdCount">0</span></div><div class="card"><b>would_create_count</b><br><span id="wouldCreateCount">0</span></div><div class="card"><b>skipped_count</b><br><span id="skippedCount">0</span></div></div><div class="cards" id="cards"></div><h2>Ostatnia partia</h2><table><thead><tr><th>local_category_id</th><th>local_category_name</th><th>suggested_allegro_category_id</th><th>suggested_allegro_category_name</th><th>matched_products_count</th><th>suggested_share</th><th>confidence</th><th>action</th><th>status/reason</th></tr></thead><tbody id="rows"></tbody></table><h2>JSON / diagnostics</h2><pre id="out"></pre>
<script>
const token = __TOKEN_JSON__; let running=false, totals={}, batchNumber=0; let processedRows=0, nextOffset=0, createdCount=0, wouldCreateCount=0, skippedCount=0;
function v(id){return document.getElementById(id).value} function c(id){return document.getElementById(id).checked?'1':'0'} function sleep(ms){return new Promise(r=>setTimeout(r,ms))}
function params(confirm){let p=new URLSearchParams({token,diagnostics:'1',offset:v('offset'),batch_size:v('batch_size'),record_limit:v('record_limit'),sample_limit:v('sample_limit'),dry_run:confirm?'0':'1',only_missing_allegro:c('only_missing_allegro'),only_public:c('only_public'),leaf_only:c('leaf_only'),exclude_uncategorized:c('exclude_uncategorized'),min_suggested_share:v('min_suggested_share'),min_matched_products:v('min_matched_products')}); if(confirm)p.set('confirm','1'); if(v('confidence'))p.set('confidence',v('confidence')); return p;}
function addTotals(d){['matched_products_count','unmatched_products_count','suggested_mapping_count','would_create_count','created_count','skipped_uncategorized_count','skipped_existing_mapping_count','skipped_low_confidence_count','skipped_low_share_count','errors_count'].forEach(k=>totals[k]=(totals[k]||0)+(Number(d[k]||0))); createdCount=totals.created_count||0; wouldCreateCount=totals.would_create_count||0; skippedCount=Object.entries(totals).filter(([k])=>k.startsWith('skipped_')).reduce((sum,[,val])=>sum+Number(val||0),0);}
function render(d){addTotals(d); let limit=Number(v('record_limit')); processedRows=Number(d.next_offset||0); nextOffset=processedRows; document.getElementById('offset').value=nextOffset; document.getElementById('progress').value=Math.min(100, Math.round(processedRows*100/limit)); document.getElementById('processedRows').textContent=processedRows; document.getElementById('recordLimit').textContent=limit; document.getElementById('batchNumber').textContent=d.batch_number||batchNumber; document.getElementById('createdCount').textContent=createdCount; document.getElementById('wouldCreateCount').textContent=wouldCreateCount; document.getElementById('skippedCount').textContent=skippedCount; document.getElementById('cards').innerHTML=Object.entries(totals).map(([k,val])=>`<div class="card"><b>${k}</b><br>${val}</div>`).join(''); document.getElementById('rows').innerHTML=(d.items||[]).map(i=>`<tr><td>${i.local_category_id??''}</td><td>${i.local_category_name??''}</td><td>${i.suggested_allegro_category_id??''}</td><td>${i.suggested_allegro_category_name??''}</td><td>${i.matched_products_count??0}</td><td>${i.suggested_share??''}</td><td>${i.confidence??''}</td><td>${i.action??''}</td><td>${i.status??''}${i.reason?' / '+i.reason:''}</td></tr>`).join(''); document.getElementById('out').textContent=JSON.stringify(d,null,2);}
async function start(confirm){running=true; totals={}; batchNumber=0; while(running){batchNumber++; let res=await fetch('/tools/run-allegro-legacy-category-mapping-batch?'+params(confirm)); let d=await res.json(); render(d); if(!res.ok||!d.ok){running=false; break;} if(d.done){running=false; break;} await sleep(300);} }
function stopRunner(){running=false;}
</script></body></html>
HTML;

        return str_replace('__TOKEN_JSON__', $tokenJson, $html);
    }

    private function applyFlags(bool $confirm, bool $mappingsChanged): array
    {
        return ['read_only'=>! $confirm,'local_update'=>$confirm && $mappingsChanged,'ovoko_write'=>false,'allegro_write'=>false,'ebay_write'=>false,'products_changed'=>false,'offers_changed'=>false,'mappings_changed'=>$mappingsChanged];
    }

    private function buildSuggestions(array $groups, array $allegroNames, int $minProducts, ?string $confidenceFilter): array
    {
        $suggestions = [];
        foreach ($groups as $group) {
            arsort($group['counts']);
            $suggestedId = array_key_first($group['counts']);
            $suggestedCount = $suggestedId ? (int) $group['counts'][$suggestedId] : 0;
            if ($suggestedCount < $minProducts) continue;
            $share = $group['matched_products_count'] > 0 ? round($suggestedCount / $group['matched_products_count'], 4) : 0.0;
            $confidence = $share >= 0.9 ? 'high' : ($share >= 0.7 ? 'medium' : 'low');
            if ($confidenceFilter && $confidenceFilter !== $confidence) continue;
            $competing = [];
            foreach ($group['counts'] as $id => $count) {
                if ((string)$id === (string)$suggestedId) continue;
                $competing[] = ['allegro_category_id'=>(string)$id,'allegro_category_name'=>$allegroNames[(string)$id] ?? null,'count'=>(int)$count,'share'=>$group['matched_products_count'] > 0 ? round($count / $group['matched_products_count'], 4) : 0.0];
            }
            unset($group['counts']);
            $suggestions[] = $group + ['suggested_allegro_category_id'=>(string)$suggestedId,'suggested_allegro_category_name'=>$allegroNames[(string)$suggestedId] ?? null,'suggested_count'=>$suggestedCount,'suggested_share'=>$share,'confidence'=>$confidence,'competing_allegro_categories'=>$competing];
        }

        usort($suggestions, fn ($a, $b) => [$b['confidence'] === 'high', $b['suggested_share'], $b['matched_products_count']] <=> [$a['confidence'] === 'high', $a['suggested_share'], $a['matched_products_count']]);

        return $suggestions;
    }

    private function flags(): array { return ['read_only'=>true,'local_update'=>false,'ovoko_write'=>false,'allegro_write'=>false,'ebay_write'=>false,'products_changed'=>false,'offers_changed'=>false,'mappings_changed'=>false]; }
    private function possibleMatchFields(): array { return ['local offer/listing tables allegro offer id -> part_id/product_id','parts.allegro_offer_id','parts.offer_id','parts.marketplace_offer_id','parts.external_offer_id','parts.legacy_payload.allegro_offer_id','parts.legacy_payload._allegro_offer_id','parts.raw_allegro_meta_json._allegro_offer_id','parts.source_system=woo + parts.external_id','parts.external_id','parts.legacy_payload.woo_product_id','parts.legacy_payload.id','parts.legacy_payload.product_id','parts.sku','parts.oem_number','parts.part_number','parts.legacy_payload.sku']; }
    private function resolveCsvPath(): ?string { $preferred = storage_path('app/imports/woo_allegro_legacy_mapping.csv'); if (is_file($preferred)) return $preferred; foreach (glob(storage_path('app/imports/*.csv')) ?: [] as $path) if (str_contains(strtolower(basename($path)), 'allegro')) return $path; return null; }

    private function emptyDiagnostics(?string $csvPath): array
    {
        return [
            'csv_path' => $csvPath,
            'csv_found' => $csvPath !== null,
            'csv_rows_read' => 0,
            'csv_rows_skipped' => 0,
            'csv_rows_skipped_by_offset' => 0,
            'invalid_json_count' => 0,
            'allegro_category_id_from_json_count' => 0,
            'allegro_category_id_from_regex_count' => 0,
            'allegro_offer_id_from_column_count' => 0,
            'allegro_offer_id_from_regex_count' => 0,
            'sku_empty_count' => 0,
            'missing_woo_product_id_count' => 0,
            'missing_allegro_offer_id_count' => 0,
            'missing_allegro_category_id_count' => 0,
            'matched_products_count' => 0,
            'unmatched_products_count' => 0,
            'sample_woo_product_ids' => [],
            'sample_skus' => [],
            'sample_allegro_offer_ids' => [],
            'local_offer_tables_checked' => [],
            'local_offer_match_strategy_used' => null,
            'no_offer_id_storage_found' => false,
            'count_offer_table_matches_sample' => 0,
            'sample_offer_table_matches' => [],
            'offer_table_raw_match_count' => 0,
            'offer_table_part_found_count' => 0,
            'offer_table_part_missing_count' => 0,
            'offer_table_part_without_category_count' => 0,
            'offer_table_category_missing_count' => 0,
            'offer_table_rejected_not_public_count' => 0,
            'offer_table_rejected_not_leaf_count' => 0,
            'offer_table_rejected_existing_allegro_mapping_count' => 0,
            'offer_table_accepted_count' => 0,
            'count_parts_with_allegro_offer_id_matching_sample' => 0,
            'count_parts_with_offer_id_matching_sample' => 0,
            'count_parts_with_marketplace_offer_id_matching_sample' => 0,
            'count_parts_with_external_offer_id_matching_sample' => 0,
            'count_parts_with_raw_allegro_meta_json_offer_id_matching_sample' => 0,
            'product_match_attempts_sample' => [],
            'count_parts_with_external_id_matching_sample' => 0,
            'count_parts_with_legacy_payload_woo_product_id_matching_sample' => 0,
            'count_parts_with_legacy_payload_id_matching_sample' => 0,
            'count_parts_with_sku_matching_sample' => 0,
            'errors_sample' => [],
            'info_sample' => [],
            'warnings_sample' => [],
        ];
    }

    private function criticalPayload(array $diagnostics, string $error): array
    {
        return $this->flags() + [
            'ok' => false,
            'error' => $error,
            'diagnostics' => $diagnostics,
        ];
    }

    private function addDiagnosticError(array &$diagnostics, string $type, string $message, array $context = []): void
    {
        if (count($diagnostics['errors_sample']) >= 20) {
            return;
        }

        $diagnostics['errors_sample'][] = ['type' => $type, 'message' => $message, 'context' => $context];
    }

    private function addDiagnosticInfo(array &$diagnostics, string $type, string $message, array $context = []): void
    {
        if (count($diagnostics['info_sample']) >= 20) {
            return;
        }

        $diagnostics['info_sample'][] = ['type' => $type, 'message' => $message, 'context' => $context];
    }

    private function diagnosticErrorsCount(array $diagnostics): int
    {
        return count(array_filter(
            $diagnostics['errors_sample'] ?? [],
            fn (array $error) => ($error['type'] ?? null) !== 'record_limit_reached'
        ));
    }

    private function readRows(string $path, array &$diagnostics): iterable
    {
        try {
            $file = new SplFileObject($path);
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $headers = null;
            foreach ($file as $rowNumber => $row) {
                try {
                    if (! is_array($row) || $row === [null]) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn($v) => trim((string)$v), $row);
                        foreach (['woo_product_id', 'allegro_offer_id', 'raw_allegro_meta_json'] as $requiredHeader) {
                            if (! in_array($requiredHeader, $headers, true)) {
                                $this->addDiagnosticError($diagnostics, 'missing_csv_column', "Missing CSV column: {$requiredHeader}");
                            }
                        }
                        continue;
                    }
                    $normalizedRow = array_slice(array_pad($row, count($headers), null), 0, count($headers));
                    $combined = array_combine($headers, $normalizedRow) ?: [];
                    $combined['__raw_csv_row'] = implode(',', array_map(fn ($value) => (string) $value, $row));
                    $combined['__all_columns'] = implode(',', array_map(fn ($value) => (string) $value, $normalizedRow));
                    yield $combined;
                } catch (Throwable $e) {
                    $diagnostics['csv_rows_skipped']++;
                    $this->addDiagnosticError($diagnostics, 'csv_row_parse_failed', $e->getMessage(), ['row_number' => $rowNumber + 1]);
                }
            }
        } catch (Throwable $e) {
            $this->addDiagnosticError($diagnostics, 'csv_parse_failed', $e->getMessage());
        }
    }

    private function extractAllegroCategoryId(array $row, array &$diagnostics): ?string
    {
        $json = (string) ($row['raw_allegro_meta_json'] ?? '');
        $rawFallback = $this->rowSearchText($row);

        if (trim($json) !== '') {
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    $categoryId = data_get($data, '_allegro_category_id', data_get($data, 'meta._allegro_category_id'));
                    if ($categoryId !== null && $categoryId !== '') {
                        $diagnostics['allegro_category_id_from_json_count']++;

                        return (string) $categoryId;
                    }
                }
            } catch (Throwable $e) {
                // Broken legacy CSV rows often split JSON on commas. Treat invalid JSON as
                // diagnostic only when regex fallback cannot recover the category id.
            }
        }

        $categoryId = $this->regexValue($rawFallback, '/"_allegro_category_id"\s*:\s*"([^"]+)"/');
        if ($categoryId !== null && $categoryId !== '') {
            $diagnostics['allegro_category_id_from_regex_count']++;

            return $categoryId;
        }

        if (trim($json) !== '') {
            $diagnostics['invalid_json_count']++;
        }

        return null;
    }

    private function extractAllegroOfferId(array $row, array &$diagnostics): string
    {
        $columnValue = trim((string) ($row['allegro_offer_id'] ?? ''));
        if ($columnValue !== '') {
            $diagnostics['allegro_offer_id_from_column_count']++;

            return $columnValue;
        }

        $offerId = $this->regexValue($this->rowSearchText($row), '/"_allegro_offer_id"\s*:\s*"([^"]+)"/');
        if ($offerId !== null && $offerId !== '') {
            $diagnostics['allegro_offer_id_from_regex_count']++;

            return $offerId;
        }

        return '';
    }

    private function rowSearchText(array $row): string
    {
        return implode(',', array_filter([
            (string) ($row['raw_allegro_meta_json'] ?? ''),
            (string) ($row['__raw_csv_row'] ?? ''),
            (string) ($row['__all_columns'] ?? ''),
            implode(',', array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $row)),
        ], fn ($value) => $value !== ''));
    }

    private function regexValue(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $matches) === 1 ? (string) $matches[1] : null;
    }

    private function extractCsvSku(array $row): string
    {
        foreach (['sku', 'SKU', 'product_sku', 'woo_sku', '_sku'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function addSample(array &$sample, string $value, int $limit = 20): void
    {
        if ($value === '' || count($sample) >= $limit || in_array($value, $sample, true)) {
            return;
        }

        $sample[] = $value;
    }

    private function findPart(string $wooProductId, string $sku, string $allegroOfferId, bool $onlyPublic, array &$diagnostics): ?object
    {
        if (! Schema::hasTable('parts')) return null;
        $categoryNameSelect = Schema::hasColumn('part_categories', 'name') ? 'part_categories.name as category_name' : DB::raw('NULL as category_name');
        $categoryPathSelect = Schema::hasColumn('part_categories', 'category_path') ? 'part_categories.category_path' : DB::raw('NULL as category_path');
        $legacyPayloadSelect = Schema::hasColumn('parts', 'legacy_payload') ? 'parts.legacy_payload' : DB::raw('NULL as legacy_payload');
        $q = DB::table('parts')->leftJoin('part_categories', 'parts.category_id', '=', 'part_categories.id')->select('parts.id','parts.external_id','parts.sku','parts.name','parts.category_id',$legacyPayloadSelect,$categoryNameSelect,$categoryPathSelect);
        $attempt = ['woo_product_id' => $wooProductId !== '' ? $wooProductId : null, 'allegro_offer_id' => $allegroOfferId !== '' ? $allegroOfferId : null, 'sku' => $sku !== '' ? $sku : null, 'matched_by' => null, 'matched_part_id' => null, 'matched_category_id' => null, 'matched_offer_table' => null, 'matched_offer_row_id' => null, 'raw_offer_table_match_count' => 0, 'rejected_reason' => null, 'match_count' => 0];

        if ($allegroOfferId !== '') {
            $part = $this->findPartByOfferTable($q, $allegroOfferId, $attempt, $diagnostics);
            if ($part) return $part;
            if ($this->hasAmbiguousOfferMatch($diagnostics, $allegroOfferId)) return null;

            $part = $this->findPartByOfferColumns($q, $allegroOfferId, $attempt, $diagnostics);
            if ($part) return $part;
            if ($this->hasAmbiguousOfferMatch($diagnostics, $allegroOfferId)) return null;

            $part = $this->findPartByOfferJson($q, $allegroOfferId, $attempt, $diagnostics);
            if ($part) return $part;
            if ($this->hasAmbiguousOfferMatch($diagnostics, $allegroOfferId)) return null;

            $diagnostics['no_offer_id_storage_found'] = ! $this->hasLocalOfferIdStorage($diagnostics);
        }

        if ($wooProductId !== '') {
            $externalMatches = (clone $q)->where(fn($w) => $w->where(fn($x) => $x->where('parts.source_system','woo')->where('parts.external_id',$wooProductId))->orWhere('parts.external_id',$wooProductId))->limit(2)->get();
            $diagnostics['count_parts_with_external_id_matching_sample'] += $externalMatches->count();
            if ($externalMatches->count() === 1) {
                $attempt['matched_by'] = 'external_id';
                $attempt['match_count'] = 1;
                return $this->matchedPart($externalMatches->first(), 'external_id', $attempt, $diagnostics);
            }

            if (Schema::hasColumn('parts', 'legacy_payload')) {
                $wooPayloadMatches = (clone $q)->where('parts.legacy_payload->woo_product_id', $wooProductId)->limit(2)->get();
                $diagnostics['count_parts_with_legacy_payload_woo_product_id_matching_sample'] += $wooPayloadMatches->count();
                if ($wooPayloadMatches->count() === 1) {
                    return $this->matchedPart($wooPayloadMatches->first(), 'legacy_payload.woo_product_id', $attempt + ['match_count' => 1], $diagnostics);
                }

                $idPayloadMatches = (clone $q)->where(function($w) use ($wooProductId) { foreach (['id','product_id'] as $key) $w->orWhere("parts.legacy_payload->$key", $wooProductId); })->limit(2)->get();
                $diagnostics['count_parts_with_legacy_payload_id_matching_sample'] += $idPayloadMatches->count();
                if ($idPayloadMatches->count() === 1) {
                    return $this->matchedPart($idPayloadMatches->first(), 'legacy_payload.id', $attempt + ['match_count' => 1], $diagnostics);
                }
            }
        }

        if ($sku !== '') {
            $skuMatches = (clone $q)->where(function ($w) use ($sku) {
                if (Schema::hasColumn('parts', 'sku')) $w->orWhere('parts.sku', $sku);
                if (Schema::hasColumn('parts', 'oem_number')) $w->orWhere('parts.oem_number', $sku);
                if (Schema::hasColumn('parts', 'part_number')) $w->orWhere('parts.part_number', $sku);
                if (Schema::hasColumn('parts', 'legacy_payload')) $w->orWhere('parts.legacy_payload->sku', $sku);
            })->limit(2)->get();
            $diagnostics['count_parts_with_sku_matching_sample'] += $skuMatches->count();
            if ($skuMatches->count() === 1) {
                return $this->matchedPart($skuMatches->first(), 'sku', $attempt + ['match_count' => 1], $diagnostics);
            }
        }

        $this->addMatchAttemptSample($diagnostics, $attempt);

        return null;
    }

    private function addMatchAttemptSample(array &$diagnostics, array $attempt, int $limit = 20): void
    {
        if (count($diagnostics['product_match_attempts_sample']) >= $limit) {
            return;
        }

        $diagnostics['product_match_attempts_sample'][] = $attempt;
    }

    private function findPartByOfferTable($baseQuery, string $offerId, array $attempt, array &$diagnostics): ?object
    {
        foreach (['offers','marketplace_offers','marketplace_listings','allegro_offers','part_marketplace_offers','product_marketplace_offers','marketplace_products','marketplace_product_offers'] as $table) {
            $checked = ['table' => $table, 'exists' => Schema::hasTable($table), 'offer_id_columns' => [], 'relation_columns' => [], 'channel_columns' => []];
            if (! $checked['exists']) {
                $this->addOfferTableChecked($diagnostics, $checked);
                continue;
            }

            $offerColumns = array_values(array_filter(['allegro_offer_id','offer_id','external_offer_id','marketplace_offer_id','external_id'], fn ($column) => Schema::hasColumn($table, $column)));
            $relationColumns = array_values(array_filter(['part_id','product_id'], fn ($column) => Schema::hasColumn($table, $column)));
            $channelColumns = array_values(array_filter(['channel','marketplace','source'], fn ($column) => Schema::hasColumn($table, $column)));
            $checked['offer_id_columns'] = $offerColumns;
            $checked['relation_columns'] = $relationColumns;
            $checked['channel_columns'] = $channelColumns;
            $this->addOfferTableChecked($diagnostics, $checked);

            if ($offerColumns === [] || $relationColumns === []) continue;

            $rows = DB::table($table)
                ->where(function ($q) use ($table, $offerColumns, $offerId) {
                    foreach ($offerColumns as $column) $q->orWhere("{$table}.{$column}", $offerId);
                })
                ->when($channelColumns !== [], function ($q) use ($table, $channelColumns) {
                    $q->where(function ($w) use ($table, $channelColumns) {
                        foreach ($channelColumns as $column) $w->orWhereIn("{$table}.{$column}", ['allegro', 'Allegro', 'ALLEGRO']);
                    });
                })
                ->limit(3)
                ->get();

            $diagnostics['offer_table_raw_match_count'] += $rows->count();
            $ids = collect();
            $matchedOfferRow = null;
            $matchedOfferColumn = null;
            foreach ($rows as $row) {
                $offerColumn = collect($offerColumns)->first(fn ($column) => (string) ($row->{$column} ?? '') === $offerId) ?? $offerColumns[0];
                $relationColumn = collect($relationColumns)->first(fn ($column) => ($row->{$column} ?? null) !== null && ($row->{$column} ?? '') !== '');
                $partId = $relationColumn ? ($row->{$relationColumn} ?? null) : null;
                if ($partId === null || $partId === '') {
                    $diagnostics['offer_table_part_missing_count']++;
                } else {
                    $ids->push($partId);
                    $matchedOfferRow ??= $row;
                    $matchedOfferColumn ??= $offerColumn;
                    $this->addOfferTableMatchSample($diagnostics, $offerId, $table, $offerColumn, $row, $partId);
                }
            }

            $ids = $ids->unique()->values();
            $diagnostics['count_offer_table_matches_sample'] += $ids->count();
            $attempt['raw_offer_table_match_count'] += $rows->count();
            if ($ids->count() > 1) {
                $this->addDiagnosticError($diagnostics, 'ambiguous_allegro_offer_id', 'More than one local product matched the Allegro offer ID.', ['allegro_offer_id' => $offerId, 'table' => $table, 'matched_ids' => $ids->all()]);
                $this->addMatchAttemptSample($diagnostics, $attempt + ['matched_by' => 'ambiguous', 'matched_offer_table' => $table, 'match_count' => $ids->count()]);
                return null;
            }
            if ($ids->count() === 1) {
                $matches = (clone $baseQuery)->where('parts.id', $ids->first())->limit(2)->get();
                if ($matches->count() === 1) return $this->matchedPart($matches->first(), "{$table}.{$matchedOfferColumn}", $attempt + ['match_count' => 1, 'matched_offer_table' => $table, 'matched_offer_row_id' => $matchedOfferRow->id ?? null], $diagnostics);
            }
        }

        return null;
    }

    private function findPartByOfferColumns($baseQuery, string $offerId, array $attempt, array &$diagnostics): ?object
    {
        foreach (['allegro_offer_id','offer_id','marketplace_offer_id','external_offer_id'] as $column) {
            if (! Schema::hasColumn('parts', $column)) continue;
            $matches = (clone $baseQuery)->where("parts.{$column}", $offerId)->limit(2)->get();
            $diagnostics["count_parts_with_{$column}_matching_sample"] += $matches->count();
            if ($matches->count() > 1) {
                $this->addDiagnosticError($diagnostics, 'ambiguous_allegro_offer_id', 'More than one part matched the Allegro offer ID column.', ['allegro_offer_id' => $offerId, 'column' => "parts.{$column}"]);
                $this->addMatchAttemptSample($diagnostics, $attempt + ['matched_by' => 'ambiguous', 'match_count' => $matches->count()]);
                return null;
            }
            if ($matches->count() === 1) return $this->matchedPart($matches->first(), "parts.{$column}", $attempt + ['match_count' => 1], $diagnostics);
        }

        return null;
    }

    private function findPartByOfferJson($baseQuery, string $offerId, array $attempt, array &$diagnostics): ?object
    {
        $jsonPaths = [];
        if (Schema::hasColumn('parts', 'legacy_payload')) {
            $jsonPaths[] = ['parts.legacy_payload->allegro_offer_id', 'legacy_payload.allegro_offer_id', null];
            $jsonPaths[] = ['parts.legacy_payload->_allegro_offer_id', 'legacy_payload._allegro_offer_id', null];
        }
        if (Schema::hasColumn('parts', 'raw_allegro_meta_json')) {
            $jsonPaths[] = ['parts.raw_allegro_meta_json->_allegro_offer_id', 'raw_allegro_meta_json._allegro_offer_id', 'count_parts_with_raw_allegro_meta_json_offer_id_matching_sample'];
        }

        foreach ($jsonPaths as [$path, $name, $counter]) {
            $matches = (clone $baseQuery)->where($path, $offerId)->limit(2)->get();
            if ($counter) $diagnostics[$counter] += $matches->count();
            if ($matches->count() > 1) {
                $this->addDiagnosticError($diagnostics, 'ambiguous_allegro_offer_id', 'More than one part matched the Allegro offer ID JSON path.', ['allegro_offer_id' => $offerId, 'json_path' => $name]);
                $this->addMatchAttemptSample($diagnostics, $attempt + ['matched_by' => 'ambiguous', 'match_count' => $matches->count()]);
                return null;
            }
            if ($matches->count() === 1) return $this->matchedPart($matches->first(), $name, $attempt + ['match_count' => 1], $diagnostics);
        }

        return null;
    }

    private function matchedPart(object $part, string $matchedBy, array $attempt, array &$diagnostics): object
    {
        $diagnostics['local_offer_match_strategy_used'] ??= $matchedBy;
        $attempt['matched_by'] = $matchedBy;
        $attempt['matched_part_id'] = (int) $part->id;
        $attempt['matched_category_id'] = $part->category_id !== null ? (int) $part->category_id : null;
        $attempt['match_count'] = $attempt['match_count'] ?: 1;
        $this->addMatchAttemptSample($diagnostics, $attempt);

        return $part;
    }

    private function hasLocalOfferIdStorage(array $diagnostics): bool
    {
        foreach ($diagnostics['local_offer_tables_checked'] as $checked) {
            if (($checked['exists'] ?? false) && ($checked['offer_id_columns'] ?? []) !== [] && ($checked['relation_columns'] ?? []) !== []) {
                return true;
            }
        }

        foreach (['allegro_offer_id','offer_id','marketplace_offer_id','external_offer_id','legacy_payload','raw_allegro_meta_json'] as $column) {
            if (Schema::hasColumn('parts', $column)) return true;
        }

        return false;
    }

    private function hasAmbiguousOfferMatch(array $diagnostics, string $offerId): bool
    {
        foreach ($diagnostics['errors_sample'] as $error) {
            if (($error['type'] ?? null) === 'ambiguous_allegro_offer_id'
                && (string) data_get($error, 'context.allegro_offer_id') === $offerId) {
                return true;
            }
        }

        return false;
    }


    private function addOfferTableChecked(array &$diagnostics, array $checked): void
    {
        foreach ($diagnostics['local_offer_tables_checked'] as $existing) {
            if (($existing['table'] ?? null) === $checked['table']) return;
        }
        $diagnostics['local_offer_tables_checked'][] = $checked;
    }

    private function addOfferTableMatchSample(array &$diagnostics, string $offerId, string $table, string $offerColumn, object $row, $partId): void
    {
        if (count($diagnostics['sample_offer_table_matches']) >= 20) return;

        $part = DB::table('parts')->where('id', $partId)->first();
        $category = ($part && $part->category_id !== null && Schema::hasTable('part_categories'))
            ? DB::table('part_categories')->where('id', $part->category_id)->first()
            : null;

        if ($part) {
            $diagnostics['offer_table_part_found_count']++;
            if ($part->category_id === null) $diagnostics['offer_table_part_without_category_count']++;
        } else {
            $diagnostics['offer_table_part_missing_count']++;
        }
        if ($part && $part->category_id !== null && ! $category) $diagnostics['offer_table_category_missing_count']++;

        $categoryId = $part->category_id ?? null;
        $diagnostics['sample_offer_table_matches'][] = [
            'csv_allegro_offer_id' => $offerId,
            'table' => $table,
            'offer_id_column' => $offerColumn,
            'matched_offer_row_id' => $row->id ?? null,
            'external_offer_id' => $row->external_offer_id ?? ($row->offer_id ?? ($row->allegro_offer_id ?? null)),
            'marketplace' => $row->marketplace ?? ($row->channel ?? ($row->source ?? null)),
            'part_id' => $row->part_id ?? null,
            'product_id' => $row->product_id ?? null,
            'part_exists' => $part !== null,
            'matched_part_id' => $part ? (int) $part->id : null,
            'part_category_id' => $categoryId !== null ? (int) $categoryId : null,
            'category_exists' => $category !== null,
            'category_is_public' => $this->categoryIsPublic($category),
            'category_children_count' => $categoryId !== null ? $this->categoryChildrenCount((int) $categoryId) : null,
            'category_has_allegro_mapping' => $categoryId !== null ? $this->hasAllegroMapping((int) $categoryId) : null,
            'accepted' => null,
            'rejected_reason' => null,
        ];
    }

    private function partRejectedReason(?object $part, bool $onlyPublic, bool $leafOnly, bool $onlyMissingAllegro): ?string
    {
        if (! $part) return 'part_missing';
        if (! $part->category_id) return 'part_without_category';
        $category = Schema::hasTable('part_categories') ? DB::table('part_categories')->where('id', $part->category_id)->first() : null;
        if (! $category) return 'category_missing';
        if ($onlyPublic && ! $this->categoryIsPublic($category)) return 'not_public';
        if ($leafOnly && $this->categoryChildrenCount((int) $part->category_id) > 0) return 'not_leaf';
        if ($onlyMissingAllegro && $this->hasAllegroMapping((int) $part->category_id)) return 'existing_allegro_mapping';
        return null;
    }

    private function categoryIsPublic(?object $category): bool
    {
        if (! $category) return false;
        foreach (['is_visible', 'is_public', 'active'] as $column) {
            if (property_exists($category, $column)) return (bool) $category->{$column};
        }
        return true;
    }

    private function categoryChildrenCount(int $id): int
    {
        return Schema::hasTable('part_categories') && Schema::hasColumn('part_categories', 'parent_id') ? DB::table('part_categories')->where('parent_id', $id)->count() : 0;
    }

    private function annotateLastMatchAttempt(array &$diagnostics, string $offerId, ?string $reason): void
    {
        for ($i = count($diagnostics['product_match_attempts_sample']) - 1; $i >= 0; $i--) {
            if ((string) ($diagnostics['product_match_attempts_sample'][$i]['allegro_offer_id'] ?? '') === $offerId) {
                $diagnostics['product_match_attempts_sample'][$i]['rejected_reason'] = $reason;
                return;
            }
        }
    }

    private function annotateOfferTableSample(array &$diagnostics, string $offerId, bool $accepted, ?string $reason): void
    {
        foreach ($diagnostics['sample_offer_table_matches'] as &$sample) {
            if ((string) ($sample['csv_allegro_offer_id'] ?? '') === $offerId && $sample['accepted'] === null) {
                $sample['accepted'] = $accepted;
                $sample['rejected_reason'] = $reason;
                return;
            }
        }
    }


    private function lastAttemptIsOfferTable(array $diagnostics, string $offerId): bool
    {
        for ($i = count($diagnostics['product_match_attempts_sample']) - 1; $i >= 0; $i--) {
            $attempt = $diagnostics['product_match_attempts_sample'][$i];
            if ((string) ($attempt['allegro_offer_id'] ?? '') === $offerId) {
                return ! empty($attempt['matched_offer_table']);
            }
        }
        return false;
    }

    private function incrementOfferTableRejectionCounter(array &$diagnostics, string $reason): void
    {
        $map = ['not_public' => 'offer_table_rejected_not_public_count', 'not_leaf' => 'offer_table_rejected_not_leaf_count', 'existing_allegro_mapping' => 'offer_table_rejected_existing_allegro_mapping_count'];
        if (isset($map[$reason])) $diagnostics[$map[$reason]]++;
    }

    private function categoryHasChildren(int $id): bool { return Schema::hasTable('part_categories') && Schema::hasColumn('part_categories', 'parent_id') && DB::table('part_categories')->where('parent_id', $id)->exists(); }
    private function hasAllegroMapping(int $id): bool { return Schema::hasTable('marketplace_category_mappings') && DB::table('marketplace_category_mappings')->where('local_category_id',$id)->whereIn('channel',['allegro','allegro_main'])->whereNotNull('external_category_id')->where('external_category_id','<>','')->exists(); }
    private function allegroCategoryNames(): array { if (! Schema::hasTable('marketplace_categories')) return []; return DB::table('marketplace_categories')->whereIn('channel',['allegro','allegro_main'])->pluck('name','external_category_id')->mapWithKeys(fn($v,$k)=>[(string)$k=>$v])->all(); }
}
