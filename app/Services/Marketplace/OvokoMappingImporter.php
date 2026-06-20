<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OvokoMappingImporter
{
    public function import(string $file, bool $dryRun = false): array
    {
        if (! is_readable($file)) {
            throw new \InvalidArgumentException("CSV file is not readable: {$file}");
        }

        $summary = ['rows_total'=>0,'rows_with_ovoko_id'=>0,'would_create'=>0,'would_update'=>0,'would_auto_match'=>0,'would_unmatched'=>0,'would_conflict'=>0,'created'=>0,'updated'=>0,'auto_matched'=>0,'unmatched'=>0,'conflict'=>0,'sample_auto_matches'=>[],'sample_unmatched'=>[],'sample_conflicts'=>[]];
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $account = $dryRun ? null : MarketplaceAccount::query()->firstOrCreate(['code' => 'ovoko_main'], ['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'status' => 'active']);

        while (($values = fgetcsv($handle)) !== false) {
            $summary['rows_total']++;
            $row = array_combine($headers, array_pad($values, count($headers), null));
            if (! is_array($row)) { continue; }
            $ovokoId = trim((string) ($row['ovoko_part_id'] ?? ''));
            if ($ovokoId !== '') { $summary['rows_with_ovoko_id']++; }
            $match = $this->matchPart($row, $ovokoId);
            $payload = $this->payload($row);
            $attributes = $this->attributes($row, $ovokoId, $match, $payload, $account?->id);
            $exists = $ovokoId !== '' ? MarketplaceListing::query()->where('marketplace', 'ovoko')->where('external_offer_id', $ovokoId)->exists() : false;
            $summary[$exists ? 'would_update' : 'would_create']++;
            $wouldKey = $match['bucket'] === 'auto_matched' ? 'would_auto_match' : 'would_'.$match['bucket'];
            $summary[$wouldKey]++;
            $this->sample($summary, $match['bucket'], $row, $match);
            if (! $dryRun) {
                DB::transaction(function () use ($ovokoId, $attributes, $match, &$summary): void {
                    $listing = MarketplaceListing::query()->updateOrCreate(['marketplace' => 'ovoko', 'external_offer_id' => $ovokoId ?: null], $attributes);
                    $summary[$listing->wasRecentlyCreated ? 'created' : 'updated']++;
                    $summary[$match['bucket']]++;
                    MarketplaceSyncLog::query()->create(['marketplace'=>'ovoko','marketplace_listing_id'=>$listing->id,'part_id'=>$listing->part_id,'action'=>'import_ovoko_mapping','status'=>'success','message'=>$match['reason'],'payload'=>['external_offer_id'=>$listing->external_offer_id,'match_status'=>$listing->match_status],'created_at'=>now()]);
                });
            }
        }
        fclose($handle);
        return $summary;
    }

    private function attributes(array $row, string $ovokoId, array $match, array $payload, ?int $accountId): array
    {
        return ['marketplace'=>'ovoko','marketplace_account_id'=>$accountId,'part_id'=>$match['part_id'],'external_offer_id'=>$ovokoId ?: null,'sku'=>$this->blankNull($row['sku'] ?? null),'title'=>$this->blankNull($row['title'] ?? null),'price'=>is_numeric($row['price'] ?? null) ? (float) $row['price'] : null,'quantity'=>is_numeric($row['stock_quantity'] ?? null) ? (int) $row['stock_quantity'] : null,'currency'=>'PLN','status'=>$this->blankNull($row['current_status'] ?? null),'raw_payload'=>$payload,'sync_status'=>$match['sync_status'],'match_status'=>$match['match_status'],'match_confidence'=>$match['confidence'],'match_reason'=>$match['reason'],'last_synced_at'=>now()];
    }

    private function matchPart(array $row, string $ovokoId): array
    {
        if ($ovokoId === '') return $this->match(null, 'unmatched', 'unmatched', 0, 'missing_ovoko_part_id');
        $woo = trim((string) ($row['woo_product_id'] ?? ''));
        foreach ([['source_system'=>'woo','external_id'=>$woo], ['external_id'=>$woo]] as $where) {
            if ($woo === '') continue;
            $q = Part::query(); foreach ($where as $k=>$v) $q->where($k, $v);
            $m = $this->unique($q); if ($m !== null) return $m === false ? $this->match(null,'conflict','conflict',0,'multiple_parts_for_woo_product_id') : $this->match($m->id,'mapped','auto_matched',100,'matched_by_woo_product_id');
        }
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku !== '') { $m = $this->unique(Part::query()->where('sku', $sku)); if ($m !== null) return $m === false ? $this->match(null,'conflict','conflict',0,'multiple_parts_for_sku') : $this->match($m->id,'mapped','auto_matched',95,'matched_by_sku'); }
        $m = $this->unique(Part::query()->where('legacy_payload', 'like', '%'.$ovokoId.'%'));
        if ($m !== null) return $m === false ? $this->match(null,'conflict','conflict',0,'multiple_parts_for_legacy_ovoko_part_id') : $this->match($m->id,'mapped','auto_matched',90,'matched_by_legacy_ovoko_part_id');
        return $this->match(null, 'unmatched', 'unmatched', 0, 'no_laravel_part_match');
    }

    private function unique($query): Part|false|null { $items = $query->limit(2)->get(); return $items->count() === 0 ? null : ($items->count() > 1 ? false : $items->first()); }
    private function match(?int $partId, string $sync, string $match, int $confidence, string $reason): array { return ['part_id'=>$partId,'sync_status'=>$sync,'match_status'=>$match,'confidence'=>$confidence,'reason'=>$reason,'bucket'=>$sync === 'mapped' ? 'auto_matched' : $sync]; }
    private function blankNull($value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function payload(array $row): array { foreach (['raw_ovoko_meta_json','raw_relevant_meta_json'] as $key) if (isset($row[$key]) && trim((string)$row[$key]) !== '') { try { $row[$key.'_decoded'] = json_decode((string)$row[$key], true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { $row[$key.'_malformed'] = true; } } return $row; }
    private function sample(array &$summary, string $bucket, array $row, array $match): void { $key = 'sample_'.($bucket === 'auto_matched' ? 'auto_matches' : $bucket); if (isset($summary[$key]) && count($summary[$key]) < 5) $summary[$key][] = Arr::only($row, ['woo_product_id','sku','ovoko_part_id','title']) + ['part_id'=>$match['part_id'],'reason'=>$match['reason']]; }
}
