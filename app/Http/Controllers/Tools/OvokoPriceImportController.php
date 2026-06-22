<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OvokoPriceImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const RUN_TYPE = 'ovoko_price_import';
    private const BATCH_SIZE = 100;


    public function startRun(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;

        $mode = (string) $request->query('mode', 'dry_run');
        if (! in_array($mode, ['dry_run', 'live'], true)) {
            return response()->json(['ok' => false, 'blocker' => 'invalid_mode'], 422);
        }
        if ($mode === 'live' && (string) $request->query('confirm', '0') !== '1') {
            return response()->json(['ok' => false, 'blocker' => 'live_import_requires_confirm'], 422);
        }

        $onlyMissing = (bool) (int) $request->query('only_missing', 0);
        $client = $manager->client('ovoko');
        $blockers = [];
        if (! $client instanceof OvokoApiClient) {
            $blockers[] = 'ovoko_client_unavailable';
        } else {
            $blockers = array_merge($blockers, $client->getAccountReadiness()['blockers'] ?? []);
        }

        $runId = (string) Str::uuid();
        $run = $this->newRunState($runId, $mode, $onlyMissing);
        if ($blockers !== []) {
            $run['status'] = 'failed';
            $run['blockers'] = $blockers;
            $run['finished_at'] = now()->toIso8601String();
        }
        $this->writeRun($run);

        return response()->json([
            'ok' => $blockers === [],
            'run_id' => $runId,
            'mode' => $mode,
            'only_missing' => $onlyMissing,
            'batch_size' => self::BATCH_SIZE,
            'status' => $run['status'],
            'runner_url' => url('/tools/ovoko-price-import-runner').'?'.http_build_query(['token' => self::TOKEN, 'run_id' => $runId]),
            'next_batch_url' => url('/tools/run-ovoko-price-import-batch').'?'.http_build_query(['token' => self::TOKEN, 'run_id' => $runId]),
            'blockers' => $blockers,
        ], $blockers === [] ? 200 : 422);
    }

    public function runner(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response('Invalid diagnostics token.', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        $runId = e((string) $request->query('run_id', ''));
        $token = e(self::TOKEN);

        return response(<<<HTML
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ovoko price import runner</title><style>body{font-family:system-ui,sans-serif;margin:2rem;line-height:1.45}button{margin:.25rem;padding:.55rem .8rem}pre{background:#111;color:#eee;padding:1rem;overflow:auto}.ok{color:#0a7f35}.bad{color:#b00020}.muted{color:#666}</style></head><body>
<h1>Ovoko price import runner</h1><p>Run: <code id="runId">{$runId}</code></p><p class="muted">Auto-run jest domyślnie włączony. Batch size: 100. Live zmienia wyłącznie lokalne <code>parts.ovoko_price</code>.</p>
<button id="start">Start / Resume</button><button id="pause">Pause</button><button id="next">Run next batch manually</button><p id="state">Loading...</p><pre id="out"></pre>
<script>
const token='{$token}', runId='{$runId}'; let auto=true, busy=false, timer=null;
const out=document.getElementById('out'), state=document.getElementById('state');
function show(data){ out.textContent=JSON.stringify(data,null,2); const cls=data.ok===false||data.status==='failed'||(data.blockers&&data.blockers.length)?'bad':'ok'; state.className=cls; state.textContent='status='+(data.status||'unknown')+' page='+(data.current_page||'?')+' processed='+(data.processed_count_total||0)+' updated='+(data.updated_count_total||0)+' would_update='+(data.would_update_count_total||0); }
async function runBatch(){ if(busy) return; busy=true; clearTimeout(timer); try{ const r=await fetch('/tools/run-ovoko-price-import-batch?'+new URLSearchParams({token,run_id:runId}),{cache:'no-store'}); const data=await r.json(); show(data); if(!data.ok||data.status==='failed'||(data.blockers&&data.blockers.length)){ auto=false; return; } if(data.status==='completed'){ auto=false; return; } if(auto && data.has_more){ timer=setTimeout(runBatch, 1500); } } catch(e){ show({ok:false,status:'failed',blockers:['runner_fetch_failed'],error:String(e)}); auto=false; } finally{ busy=false; } }
document.getElementById('start').onclick=()=>{auto=true;runBatch()}; document.getElementById('pause').onclick=()=>{auto=false;clearTimeout(timer);state.textContent+=' (paused)'}; document.getElementById('next').onclick=()=>{auto=false;runBatch()}; runBatch();
</script></body></html>
HTML)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function checkRun(Request $request): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $run = $this->readRun((string) $request->query('run_id', ''));
        if (! $run) return response()->json(['ok' => false, 'blockers' => ['run_not_found']], 404);
        return response()->json($this->statusPayload($run));
    }

    public function runBatch(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $runId = (string) $request->query('run_id', '');
        return $this->withRunLock($runId, function () use ($runId, $manager) {
            $run = $this->readRun($runId);
            if (! $run) return response()->json(['ok' => false, 'blockers' => ['run_not_found']], 404);
            if (in_array($run['status'], ['completed', 'failed'], true)) return response()->json($this->batchPayload($run, []));
            $run['status'] = 'running';
            $page = (int) $run['current_page'];
            $client = $manager->client('ovoko');
            if (! $client instanceof OvokoApiClient) return $this->failRun($run, ['ovoko_client_unavailable']);

            try { $result = $client->fetchPartsPage($page, self::BATCH_SIZE); }
            catch (Throwable) { return $this->failRun($run, ['ovoko_api_request_failed'], $client->safeExceptionDiagnostics($page, self::BATCH_SIZE, 'Ovoko/RRR API request failed without exposing credentials.')); }
            if (! ($result['api_ok'] ?? false)) {
                return $this->failRun($run, ['ovoko_api_non_success_status: '.($result['api_status_code'] ?? 'missing').'; '.$this->safeMessage($result['error'] ?? null)], $result['diagnostics'] ?? null);
            }

            $items = $result['parts'] ?? [];
            $run['api_total_count'] ??= $result['total_count'] ?? null;
            $batch = ['matched_to_part_count'=>0,'would_update_count'=>0,'updated_count'=>0,'skipped_count'=>0,'unmatched_count'=>0,'unsafe_price_count'=>0,'sample_updated'=>[],'sample_would_update'=>[],'sample_unsafe_price'=>[]];
            $ids = array_values(array_filter(array_map(fn ($i) => (string) ($i['external_offer_id'] ?? ''), $items)));
            $listings = MarketplaceListing::query()->with('part')->where('marketplace', 'ovoko')->whereIn('external_offer_id', $ids)->get()->keyBy('external_offer_id');
            foreach ($items as $item) $this->processBatchItem($run, $batch, $listings->get((string) ($item['external_offer_id'] ?? '')), $item);

            $run['processed_count_total'] += count($items);
            $hasMore = count($items) === self::BATCH_SIZE && (($run['api_total_count'] === null) || $run['processed_count_total'] < (int) $run['api_total_count']);
            $run['current_page'] = $hasMore ? $page + 1 : $page;
            $run['finished_at'] = $hasMore ? null : now()->toIso8601String();
            $run['status'] = $hasMore ? 'running' : 'completed';
            $this->writeRun($run);
            return response()->json($this->batchPayload($run, $batch, count($items), $page, $hasMore));
        });
    }

    public function check(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager);

        return response()->json($comparison);
    }

    public function export(Request $request, MarketplaceApiManager $manager): StreamedResponse|JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager, forceLimit: 10000);
        $rows = $comparison['rows_for_export'] ?? [];

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['part_id','part_number','name','ovoko_external_offer_id','storefront_price_pln','current_ovoko_price_pln','ovoko_api_price','ovoko_api_currency','ovoko_original_price','ovoko_original_currency','ovoko_api_price_pln','price_source','price_import_safe','difference','ovoko_title','ovoko_status','ovoko_quantity','action','notes']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['part_id'], $row['part_number'], $row['name'], $row['ovoko_external_offer_id'],
                    $row['storefront_price_pln'], $row['current_ovoko_price_pln'], $row['ovoko_api_price'],
                    $row['ovoko_api_currency'], $row['ovoko_original_price'], $row['ovoko_original_currency'],
                    $row['ovoko_api_price_pln'], $row['price_source'], $row['price_import_safe'] ? 'true' : 'false',
                    $row['difference'], $row['ovoko_title'], $row['ovoko_status'], $row['ovoko_quantity'],
                    $row['action'], implode('; ', $row['notes']),
                ]);
            }
            fclose($out);
        }, 'ovoko_price_import_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function debugPriceFields(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;

        $ovokoPartId = (string) $request->query('ovoko_part_id', '');
        if ($ovokoPartId === '') {
            return response()->json(['ok' => false, 'error_message' => 'Missing ovoko_part_id.'], 422);
        }

        $client = $manager->client('ovoko');
        if (! $client instanceof OvokoApiClient) {
            return response()->json(['ok' => false, 'blockers' => ['ovoko_client_unavailable']], 422);
        }

        $readiness = $client->getAccountReadiness();
        $blockers = $readiness['blockers'] ?? [];
        if ($blockers !== []) {
            return response()->json(['ok' => false, 'blockers' => $blockers], 422);
        }

        $limit = max(1, min((int) $request->query('limit', OvokoApiClient::MAX_PARTS_PAGE_LIMIT), OvokoApiClient::MAX_PARTS_PAGE_LIMIT));
        $maxPages = max(1, min((int) $request->query('max_pages', 50), 200));
        $diagnostics = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $result = $client->fetchPartsPage($page, $limit);
            } catch (Throwable) {
                return response()->json(['ok' => false, 'blockers' => ['ovoko_api_request_failed'], 'diagnostics' => $client->safeExceptionDiagnostics($page, $limit, 'Ovoko/RRR API request failed without exposing credentials.')], 502);
            }

            $diagnostics ??= $result['diagnostics'] ?? null;
            if (! ($result['api_ok'] ?? false)) {
                return response()->json(['ok' => false, 'blockers' => ['ovoko_api_non_success_status'], 'diagnostics' => $result['diagnostics'] ?? null], 502);
            }

            foreach (($result['parts'] ?? []) as $item) {
                if ((string) ($item['external_offer_id'] ?? '') !== $ovokoPartId) continue;

                return response()->json([
                    'ok' => true,
                    'ovoko_part_id' => $ovokoPartId,
                    'endpoint_used' => $result['endpoint_used'] ?? ($result['diagnostics']['endpoint_used'] ?? null),
                    'ovoko_status_code' => $result['api_status_code'] ?? null,
                    'price' => $item['price'] ?? null,
                    'currency' => $item['currency'] ?? null,
                    'original_price' => $item['original_price'] ?? null,
                    'original_currency' => $item['original_currency'] ?? null,
                    'price_resolution' => $this->resolveApiPricePln($item),
                ]);
            }

            if (count($result['parts'] ?? []) < ($result['limit'] ?? $limit)) break;
        }

        return response()->json(['ok' => false, 'ovoko_part_id' => $ovokoPartId, 'blockers' => ['ovoko_part_not_found_in_fetched_pages'], 'diagnostics' => $diagnostics], 404);
    }

    public function import(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager, forceLimit: 10000);
        $blockers = $comparison['blockers'];
        if (($comparison['api_total_count'] ?? null) !== null && ($comparison['api_total_count'] > $comparison['api_fetched_count'])) {
            $blockers[] = 'api_fetch_is_partial';
        }
        if ($blockers !== []) {
            return response()->json(['ok' => false, 'updated_count' => 0, 'skipped_count' => $comparison['would_skip_count'], 'unmatched_count' => $comparison['unmatched_api_items_count'], 'sample_updated' => [], 'sample_skipped' => array_slice($comparison['rows_for_export'], 0, 10), 'warnings' => $comparison['warnings'], 'blockers' => $blockers], 422);
        }

        $updated = [];
        DB::transaction(function () use ($comparison, &$updated): void {
            foreach ($comparison['rows_for_export'] as $row) {
                if (($row['action'] ?? null) !== 'would_update_ovoko_price') continue;
                if (($row['price_import_safe'] ?? false) !== true) continue;
                Part::query()->whereKey($row['part_id'])->update(['ovoko_price' => $row['ovoko_api_price_pln']]);
                if (count($updated) < 10) $updated[] = $row + ['action' => 'updated_ovoko_price'];
            }
        });

        return response()->json(['ok' => true, 'updated_count' => $comparison['would_update_count'], 'skipped_count' => $comparison['would_skip_count'], 'unmatched_count' => $comparison['unmatched_api_items_count'], 'sample_updated' => $updated, 'sample_skipped' => array_slice(array_values(array_filter($comparison['rows_for_export'], fn ($r) => ($r['action'] ?? null) !== 'would_update_ovoko_price')), 0, 10), 'warnings' => $comparison['warnings'], 'blockers' => []]);
    }

    private function buildComparison(Request $request, MarketplaceApiManager $manager, ?int $forceLimit = null): array
    {
        $limit = $forceLimit ?? max(1, min((int) $request->query('limit', 1000), 10000));
        $onlyMissing = (bool) (int) $request->query('only_missing', 0);
        $changedOnly = (bool) (int) $request->query('changed_only', 0);
        $client = $manager->client('ovoko');
        $warnings = [];
        $blockers = [];
        if (! $client instanceof OvokoApiClient) {
            $blockers[] = 'ovoko_client_unavailable';
            return $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);
        }

        $readiness = $client->getAccountReadiness();
        $blockers = array_merge($blockers, $readiness['blockers'] ?? []);
        if ($blockers !== []) return $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);

        $items = [];
        $apiTotal = null;
        $firstPageDiagnostics = null;
        $page = 1;
        $pageSize = min($limit, OvokoApiClient::MAX_PARTS_PAGE_LIMIT);
        while (count($items) < $limit) {
            $requestLimit = min($pageSize, $limit - count($items));

            try {
                $result = $client->fetchPartsPage($page, $requestLimit);
            } catch (Throwable) {
                $result = [
                    'api_ok' => false,
                    'error' => 'Ovoko/RRR API request failed without exposing credentials.',
                    'diagnostics' => $client->safeExceptionDiagnostics($page, $requestLimit, 'Ovoko/RRR API request failed without exposing credentials.'),
                    'parts' => [],
                    'limit' => $requestLimit,
                ];
            }

            if ($page === 1) $firstPageDiagnostics = $result['diagnostics'] ?? null;

            if (! ($result['api_ok'] ?? false)) {
                $statusCode = $result['api_status_code'] ?? ($result['diagnostics']['ovoko_status_code'] ?? 'missing');
                $message = $result['error'] ?? ($result['diagnostics']['error_message_safe'] ?? 'Ovoko API returned a non-success response.');
                $blockers[] = $this->isOvokoPageSizeLimitError($message)
                    ? 'ovoko_api_page_size_limit: '.$statusCode.'; '.$this->safeMessage($message)
                    : 'ovoko_api_non_success_status: '.$statusCode.'; '.$this->safeMessage($message);
                break;
            }

            $apiTotal ??= $result['total_count'];
            $batch = $result['parts'];
            if ($batch === []) break;
            array_push($items, ...$batch);
            if (count($batch) < $result['limit']) break;
            $page++;
        }

        $ids = array_values(array_filter(array_map(fn ($i) => (string) $i['external_offer_id'], $items)));
        $listings = MarketplaceListing::query()->with('part')->where('marketplace', 'ovoko')->whereIn('external_offer_id', $ids)->get()->keyBy('external_offer_id');
        $localTotal = MarketplaceListing::query()->where('marketplace', 'ovoko')->count();
        $summary = $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);
        $summary['api_total_count'] = $apiTotal;
        $summary['api_fetched_count'] = count($items);
        $summary['local_ovoko_listings_total'] = $localTotal;
        $summary['safe_api_diagnostics_first_page'] = $firstPageDiagnostics;

        foreach ($items as $item) {
            $listing = $listings->get((string) $item['external_offer_id']);
            $part = $listing?->part;
            if (! $part) { $summary['unmatched_api_items_count']++; $this->push($summary['sample_unmatched_api_items'], $item); continue; }
            $summary['matched_to_part_count']++;
            $resolution = $this->resolveApiPricePln($item);
            $apiPrice = $resolution['ovoko_api_price_pln'];
            $safe = $resolution['price_import_safe'];
            $local = $this->money($part->ovoko_price);
            $diff = $apiPrice !== null && $local !== null ? round($apiPrice - $local, 2) : null;
            $same = $apiPrice !== null && $local !== null && abs($diff) < 0.01;
            $missing = $local === null;
            $different = $apiPrice !== null && $local !== null && ! $same;
            if ($missing) $summary['missing_local_ovoko_price_count']++;
            if ($same) $summary['same_price_count']++;
            if ($different) $summary['different_price_count']++;
            $action = ($safe && $apiPrice !== null && ($missing || (! $onlyMissing && $different))) ? 'would_update_ovoko_price' : 'skip';
            if ($changedOnly && $action === 'skip') continue;
            $notes = array_values(array_filter(array_merge([$missing ? 'missing_local_ovoko_price' : null, $different ? 'different_price' : null, $same ? 'same_price' : null, $apiPrice === null ? 'missing_api_price_pln' : null], $resolution['warnings'])));
            $row = ['part_id'=>$part->id,'part_number'=>$part->part_number,'name'=>$part->name,'ovoko_external_offer_id'=>(string)$item['external_offer_id'],'storefront_price_pln'=>$this->money($part->price),'current_ovoko_price_pln'=>$local,'ovoko_api_price'=>$this->money($item['price'] ?? null),'ovoko_api_currency'=>$this->currency($item['currency'] ?? null),'ovoko_original_price'=>$this->money($item['original_price'] ?? null),'ovoko_original_currency'=>$this->currency($item['original_currency'] ?? null),'ovoko_api_price_pln'=>$apiPrice,'price_source'=>$resolution['price_source'],'price_import_safe'=>$safe,'difference'=>$diff,'ovoko_title'=>$item['title'],'ovoko_status'=>$item['status'],'ovoko_quantity'=>$item['quantity'],'action'=>$action,'notes'=>$notes];
            $summary[$action === 'would_update_ovoko_price' ? 'would_update_count' : 'would_skip_count']++;
            $summary['rows_for_export'][] = $row;
            if ($action === 'would_update_ovoko_price') $this->push($summary['sample_would_update'], $row);
            if ($missing) $this->push($summary['sample_missing_local_price'], $row);
            if ($different) $this->push($summary['sample_different_price'], $row);
        }
        $summary['ok'] = $blockers === [];
        if (! (bool) (int) $request->query('debug', 0) && $summary['ok']) {
            unset($summary['safe_api_diagnostics_first_page']);
        }
        return $summary;
    }

    private function emptySummary(int $limit, bool $onlyMissing, bool $changedOnly, array $warnings, array $blockers): array
    {
        return ['ok'=>false,'limit'=>$limit,'only_missing'=>$onlyMissing,'changed_only'=>$changedOnly,'api_total_count'=>null,'api_fetched_count'=>0,'local_ovoko_listings_total'=>0,'matched_to_part_count'=>0,'unmatched_api_items_count'=>0,'missing_local_ovoko_price_count'=>0,'same_price_count'=>0,'different_price_count'=>0,'would_update_count'=>0,'would_skip_count'=>0,'sample_would_update'=>[],'sample_missing_local_price'=>[],'sample_different_price'=>[],'sample_unmatched_api_items'=>[],'safe_api_diagnostics_first_page'=>null,'warnings'=>$warnings,'blockers'=>$blockers,'rows_for_export'=>[]];
    }


    private function newRunState(string $runId, string $mode, bool $onlyMissing): array
    {
        return [
            'ok' => true, 'run_id' => $runId, 'type' => self::RUN_TYPE, 'mode' => $mode, 'only_missing' => $onlyMissing,
            'status' => 'pending', 'batch_size' => self::BATCH_SIZE, 'started_at' => now()->toIso8601String(), 'finished_at' => null,
            'current_page' => 1, 'api_total_count' => null, 'processed_count_total' => 0, 'matched_to_part_count_total' => 0,
            'would_update_count_total' => 0, 'updated_count_total' => 0, 'skipped_count_total' => 0, 'unmatched_count_total' => 0,
            'unsafe_price_count_total' => 0, 'warnings' => [], 'blockers' => [],
        ];
    }

    private function processBatchItem(array &$run, array &$batch, ?MarketplaceListing $listing, array $item): void
    {
        $part = $listing?->part;
        if (! $part) {
            $batch['unmatched_count']++; $run['unmatched_count_total']++;
            return;
        }
        $batch['matched_to_part_count']++; $run['matched_to_part_count_total']++;
        $resolution = $this->resolveApiPricePln($item);
        $apiPrice = $resolution['ovoko_api_price_pln'];
        $local = $this->money($part->ovoko_price);
        $same = $apiPrice !== null && $local !== null && abs(round($apiPrice - $local, 2)) < 0.01;
        $shouldUpdate = ($resolution['price_import_safe'] ?? false) === true && $apiPrice !== null && ($local === null || (! $run['only_missing'] && ! $same));
        $sample = ['part_id'=>$part->id,'ovoko_external_offer_id'=>(string)($item['external_offer_id'] ?? ''),'current_ovoko_price_pln'=>$local,'ovoko_api_price_pln'=>$apiPrice,'price_source'=>$resolution['price_source'],'price_import_safe'=>$resolution['price_import_safe'],'notes'=>$resolution['warnings']];
        if (! ($resolution['price_import_safe'] ?? false)) {
            $batch['unsafe_price_count']++; $run['unsafe_price_count_total']++; $batch['skipped_count']++; $run['skipped_count_total']++;
            $this->push($batch['sample_unsafe_price'], $sample);
            return;
        }
        if (! $shouldUpdate) {
            $batch['skipped_count']++; $run['skipped_count_total']++;
            return;
        }
        $batch['would_update_count']++; $run['would_update_count_total']++;
        $this->push($batch['sample_would_update'], $sample);
        if ($run['mode'] === 'live') {
            $changed = Part::query()->whereKey($part->id)->where(function ($q) use ($apiPrice): void {
                $q->whereNull('ovoko_price')->orWhere('ovoko_price', '!=', $apiPrice);
            })->update(['ovoko_price' => $apiPrice]);
            if ($changed > 0) {
                $batch['updated_count']++; $run['updated_count_total']++;
                $this->push($batch['sample_updated'], $sample + ['action' => 'updated_ovoko_price']);
            } else {
                $batch['skipped_count']++; $run['skipped_count_total']++;
            }
        }
    }

    private function batchPayload(array $run, array $batch, int $fetched = 0, ?int $page = null, ?bool $hasMore = null): array
    {
        $hasMore ??= $run['status'] === 'running';
        $page ??= max(1, ((int) $run['current_page']) - ($hasMore ? 1 : 0));
        return array_merge($this->statusPayload($run), [
            'current_page' => $page, 'next_page' => $hasMore ? (int) $run['current_page'] : null, 'batch_size' => self::BATCH_SIZE,
            'api_fetched_in_batch' => $fetched, 'has_more' => $hasMore,
            'next_url' => $hasMore ? url('/tools/run-ovoko-price-import-batch').'?'.http_build_query(['token'=>self::TOKEN,'run_id'=>$run['run_id']]) : null,
            'batch' => $batch,
        ]);
    }

    private function statusPayload(array $run): array
    {
        return collect($run)->only(['ok','run_id','mode','status','started_at','finished_at','current_page','batch_size','api_total_count','processed_count_total','matched_to_part_count_total','would_update_count_total','updated_count_total','skipped_count_total','unmatched_count_total','unsafe_price_count_total','warnings','blockers'])->all();
    }

    private function failRun(array $run, array $blockers, ?array $diagnostics = null): JsonResponse
    {
        $run['ok'] = false; $run['status'] = 'failed'; $run['blockers'] = array_values(array_merge($run['blockers'] ?? [], $blockers)); $run['finished_at'] = now()->toIso8601String();
        if ($diagnostics !== null) $run['warnings'][] = ['safe_api_diagnostics' => $diagnostics];
        $this->writeRun($run);
        return response()->json($this->batchPayload($run, []), 502);
    }

    private function runsDir(): string { return storage_path('app/private/import-runs'); }
    private function runPath(string $runId): string { return $this->runsDir().'/'.basename($runId).'.json'; }
    private function readRun(string $runId): ?array { $path = $this->runPath($runId); return is_file($path) ? json_decode((string) file_get_contents($path), true) : null; }
    private function writeRun(array $run): void
    {
        File::ensureDirectoryExists($this->runsDir());
        $path = $this->runPath((string) $run['run_id']); $tmp = $path.'.tmp.'.getmypid();
        file_put_contents($tmp, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); rename($tmp, $path);
    }

    private function withRunLock(string $runId, callable $callback): JsonResponse
    {
        File::ensureDirectoryExists($this->runsDir());
        $handle = fopen($this->runPath($runId).'.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) return response()->json(['ok'=>false,'blockers'=>['run_batch_already_in_progress']], 409);
        try { return $callback(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function guard(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    private function safeMessage(mixed $message): string
    {
        return str($message ?: 'Ovoko API returned a non-success response.')
            ->replaceMatches('/(username|password|user_token)=[^\s&]+/i', '$1=[redacted]')
            ->limit(300, '')
            ->toString();
    }

    private function isOvokoPageSizeLimitError(mixed $message): bool
    {
        return is_string($message) && str_contains(strtolower($message), 'limit maximum is 100');
    }

    private function resolveApiPricePln(array $item): array
    {
        $originalPrice = $this->money($item['original_price'] ?? null);
        $originalCurrency = $this->currency($item['original_currency'] ?? null);

        if ($originalCurrency === 'PLN' && $originalPrice !== null && $originalPrice > 0) {
            return ['ovoko_api_price_pln' => $originalPrice, 'price_source' => 'original_price_pln', 'price_import_safe' => true, 'warnings' => []];
        }

        if ($originalCurrency === 'EUR') {
            return ['ovoko_api_price_pln' => null, 'price_source' => 'blocked_original_price_eur', 'price_import_safe' => false, 'warnings' => ['blocked_original_price_eur_no_auto_conversion']];
        }

        return ['ovoko_api_price_pln' => null, 'price_source' => null, 'price_import_safe' => false, 'warnings' => ['missing_safe_original_price_pln']];
    }

    private function currency(mixed $value): ?string { return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null; }
    private function money(mixed $value): ?float { return is_numeric($value) ? round((float) $value, 2) : null; }
    private function push(array &$bucket, array $row): void { if (count($bucket) < 10) $bucket[] = $row; }
}
