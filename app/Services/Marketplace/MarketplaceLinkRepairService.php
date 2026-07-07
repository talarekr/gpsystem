<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketplaceLinkRepairService
{
    public function __construct(private readonly PartMarketplaceStatusResolver $resolver) {}

    /** @return array<string, mixed> */
    public function preview(array $filters): array
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $parts = $this->partsQuery($filters)->limit($limit)->get();
        $channels = $this->channels((string) ($filters['channel'] ?? 'both'));
        $rows = [];

        foreach ($parts as $part) {
            foreach ($channels as $channel) {
                $row = $this->plan($part, $channel, (bool) ($filters['only_resolver_broken'] ?? false));
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return [
            'ok' => true,
            'mode' => 'preview',
            'read_only' => true,
            'marketplace_write' => false,
            'sync_triggered' => false,
            'relist' => false,
            'delete_links' => false,
            'limit' => $limit,
            'filters' => $filters,
            'summary' => $this->summary($rows),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function apply(array $filters): array
    {
        $preview = $this->preview($filters);
        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => 0];
        $rows = [];

        foreach ($preview['rows'] as $row) {
            if (! in_array($row['action'], ['create', 'update'], true)) {
                $report[$row['action'] === 'conflict' ? 'conflicts' : 'skipped']++;
                $rows[] = $row;
                continue;
            }

            try {
                DB::transaction(function () use (&$row): void {
                    $part = Part::query()->lockForUpdate()->with('marketplaceListings')->findOrFail($row['part_id']);
                    $replanned = $this->plan($part, $row['channel'], false);
                    if (! $replanned || ! in_array($replanned['action'], ['create', 'update'], true)) {
                        $row = $replanned ?: $row + ['error_message' => 'repair_plan_disappeared'];
                        return;
                    }
                    $listing = $replanned['existing_listing_id']
                        ? MarketplaceListing::query()->lockForUpdate()->findOrFail($replanned['existing_listing_id'])
                        : new MarketplaceListing(['part_id' => $part->id, 'marketplace' => $replanned['marketplace']]);
                    $listing->forceFill($this->attributes($part, $replanned['channel'], $replanned['external_id'], $replanned['planned_url'], $listing));
                    $listing->save();
                    $part->refresh()->load('marketplaceListings');
                    $row = $this->plan($part, $replanned['channel'], false) ?: $replanned;
                    $row['applied'] = true;
                    $row['applied_listing_id'] = $listing->id;
                });
                $report[$row['action'] === 'create' ? 'created' : 'updated']++;
            } catch (\Throwable $e) {
                $report['errors']++;
                $row['applied'] = false;
                $row['error_message'] = $e->getMessage();
            }
            $rows[] = $row;
        }

        return $preview + ['mode' => 'apply', 'read_only' => false, 'applied' => true, 'report' => $report, 'rows' => $rows];
    }

    private function partsQuery(array $filters): Builder
    {
        return Part::query()->with('marketplaceListings')
            ->when(! empty($filters['part_id']), fn (Builder $q) => $q->where('id', (int) $filters['part_id']))
            ->when(! empty($filters['ready_only']), fn (Builder $q) => $q->where('status', 'ready')->where('quantity', '>', 0))
            ->orderBy('id');
    }

    private function plan(Part $part, string $channel, bool $onlyResolverBroken): ?array
    {
        $resolved = $this->resolveLocalId($part, $channel);
        $before = $this->resolverRow($part, $channel);
        if ($onlyResolverBroken && ($before['has_link'] ?? false) && ($before['is_active'] ?? false)) return null;
        if (! $resolved['id']) return $this->row($part, $channel, $resolved, null, [], [], $before, $before, 'skip', 'missing_id');

        $markets = $channel === 'allegro' ? ['allegro', 'allegro_main'] : ['ovoko'];
        $listings = $part->marketplaceListings->whereIn('marketplace', $markets)->values();
        $matching = $listings->first(fn ($l) => $this->listingId($l, $channel) === $resolved['id']);
        $different = $listings->first(fn ($l) => ($id = $this->listingId($l, $channel)) !== null && $id !== $resolved['id']);
        if ($different) return $this->row($part, $channel, $resolved, $different, [], [], $before, $before, 'conflict', 'part_has_different_id_for_channel');
        $dupe = $this->duplicate($channel, $resolved['id'], (int) $part->id);
        if ($dupe) return $this->row($part, $channel, $resolved, $dupe, [], [], $before, $before, 'conflict', 'external_id_belongs_to_other_part');

        $listing = $matching ?: $listings->first();
        $attrs = $this->attributes($part, $channel, $resolved['id'], $resolved['url'] ?: $this->defaultUrl($channel, $resolved['id']), $listing);
        $missing = $this->missingFields($listing, $attrs);
        $action = $listing ? ($missing === [] ? 'skip' : 'update') : 'create';
        $after = $this->resolverRow($this->simulatedPart($part, $listing, $attrs), $channel);
        return $this->row($part, $channel, $resolved, $listing, $missing, $attrs, $before, $after, $action, $action === 'skip' ? 'nothing_to_repair' : null);
    }

    private function attributes(Part $part, string $channel, string $id, string $url, ?MarketplaceListing $listing): array
    {
        $account = MarketplaceAccount::query()->firstOrCreate(['code' => $channel === 'allegro' ? 'allegro_main' : 'ovoko_main'], ['marketplace' => $channel, 'name' => ucfirst($channel).' main', 'status' => 'active']);
        $raw = is_array($listing?->raw_payload) ? $listing->raw_payload : [];
        $raw['marketplace_link_repair'] = ['source' => 'admin_tools_marketplace_link_repair', 'external_id' => $id, 'url' => $url, 'repaired_at' => now()->toISOString(), 'marketplace_write' => false, 'sync_triggered' => false];
        if ($channel === 'ovoko') $raw['ovoko_part_id'] = $id;
        return ['marketplace_account_id' => $listing?->marketplace_account_id ?: $account->id, 'part_id' => $part->id, 'marketplace' => $channel === 'allegro' ? ($listing?->marketplace ?: 'allegro') : 'ovoko', 'external_offer_id' => $id, 'external_listing_id' => $id, 'url' => $url, 'status' => $channel === 'allegro' ? 'ACTIVE' : 'imported', 'sync_status' => 'mapped', 'match_status' => 'confirmed', 'match_confidence' => 100, 'match_reason' => 'admin_marketplace_link_repair', 'sku' => $part->sku, 'title' => $part->name, 'quantity' => (int) $part->quantity, 'currency' => $part->currency ?: 'PLN', 'price' => is_numeric($channel === 'allegro' ? $part->allegro_price : $part->ovoko_price) ? (float) ($channel === 'allegro' ? $part->allegro_price : $part->ovoko_price) : null, 'raw_payload' => $raw, 'last_error' => null, 'last_api_status' => $channel === 'allegro' ? 'ACTIVE' : null, 'last_synced_at' => now()];
    }

    private function resolveLocalId(Part $part, string $channel): array
    {
        if ($channel === 'ovoko') {
            return $this->resolveOvokoLocalId($part);
        }

        $texts = [$part->legacy_url, json_encode($part->legacy_payload), $part->external_id, $part->sku];
        foreach ($part->marketplaceListings->whereIn('marketplace', ['allegro','allegro_main']) as $l) array_push($texts, $l->external_offer_id, $l->external_listing_id, $l->url, json_encode($l->raw_payload));
        foreach ($texts as $text) { $s = (string) $text; if (preg_match('/(?:oferta\/[^\s"\']*?)(\d{6,})|\b(\d{6,})\b/i', $s, $m)) return ['id' => $m[1] ?: $m[2], 'url' => preg_match('~https?://\S+~', $s, $u) ? rtrim($u[0], '"\'<>),') : null, 'source' => 'local_text']; }
        return ['id' => null, 'url' => null, 'source' => null];
    }

    private function resolveOvokoLocalId(Part $part): array
    {
        foreach ($part->marketplaceListings->whereIn('marketplace', ['ovoko']) as $listing) {
            $id = $this->listingId($listing, 'ovoko');
            if ($id !== null) return ['id' => $id, 'url' => $listing->url, 'source' => 'marketplace_listing'];
        }

        $texts = [$part->legacy_url, json_encode($part->legacy_payload), $part->external_id, $part->sku];
        foreach ($texts as $text) {
            $resolved = $this->resolveOvokoIdFromText((string) $text);
            if ($resolved['id'] !== null) return $resolved;
        }

        return ['id' => null, 'url' => null, 'source' => null];
    }

    private function resolveOvokoIdFromText(string $text): array
    {
        if ($text === '') return ['id' => null, 'url' => null, 'source' => null];

        if (preg_match('~https?://(?:www\.)?ovoko\.pl/[^\s"\'<>]*?/hgf(\d{3,})(?:\b|[-_/?.#])[^\s"\'<>]*~i', $text, $match)) {
            return ['id' => $match[1], 'url' => rtrim($match[0], '"\'<>),'), 'source' => 'ovoko_url'];
        }

        if (preg_match('/\b(?:ovoko(?:\s+(?:id|offer|listing))?|ovoko_id|ovokoPartId|ovoko_part_id|hgf)\s*[:=#-]?\s*hgf?(\d{3,})\b/i', $text, $match)) {
            return ['id' => $match[1], 'url' => null, 'source' => 'explicit_ovoko_text'];
        }

        if (preg_match('/\bhgf(\d{3,})\b/i', $text, $match) && preg_match('/\bovoko\b/i', $text)) {
            return ['id' => $match[1], 'url' => null, 'source' => 'explicit_ovoko_text'];
        }

        return ['id' => null, 'url' => null, 'source' => null];
    }

    private function duplicate(string $channel, string $id, int $partId): ?MarketplaceListing { return MarketplaceListing::query()->whereIn('marketplace', $channel === 'allegro' ? ['allegro','allegro_main'] : ['ovoko'])->where('part_id', '!=', $partId)->where(fn($q) => $q->where('external_offer_id',$id)->orWhere('external_listing_id',$id)->orWhere('url','like', $channel === 'ovoko' ? '%hgf'.$id.'%' : '%'.$id.'%'))->first(); }
    private function listingId(MarketplaceListing $l, string $c): ?string { $v = trim((string) ($l->external_offer_id ?: $l->external_listing_id)); if ($v !== '') return $c === 'ovoko' && preg_match('/hgf(\d+)/i',$v,$m) ? $m[1] : $v; $url = (string) $l->url; if ($c === 'ovoko' && preg_match('/hgf(\d+)/i', $url, $m)) return $m[1]; if ($c === 'allegro' && preg_match('/(\d{6,})\/?$/', $url, $m)) return $m[1]; return null; }
    private function defaultUrl(string $c, string $id): string { return $c === 'ovoko' ? 'https://ovoko.pl/czesci-samochodowe/hgf'.$id : 'https://allegro.pl/oferta/'.$id; }
    private function channels(string $c): array { return match ($c) { 'ovoko' => ['ovoko'], 'allegro' => ['allegro'], default => ['ovoko', 'allegro'] }; }
    private function resolverRow(Part $p, string $c): array { $r = collect($this->resolver->rowsForPart($p))->firstWhere('key', $c) ?: []; return Arr::only($r, ['has_link','is_active','icon','reason','url','external_offer_id']); }
    private function simulatedPart(Part $p, ?MarketplaceListing $old, array $attrs): Part { $clone = $p->replicate(); $clone->id = $p->id; $listings = $p->marketplaceListings->reject(fn($l) => $old && $l->id === $old->id)->values(); $new = $old ? $old->replicate() : new MarketplaceListing(); $new->forceFill($attrs); $new->id = $old?->id ?: 0; $listings->push($new); $clone->setRelation('marketplaceListings', $listings); return $clone; }
    private function missingFields(?MarketplaceListing $l, array $attrs): array { if (!$l) return array_keys(Arr::only($attrs, ['external_offer_id','external_listing_id','url','status','sync_status','match_status'])); $m=[]; foreach (['external_offer_id','external_listing_id','url','status','sync_status','match_status'] as $f) if (blank($l->$f) || ($f === 'status' && strtolower((string)$l->$f) !== strtolower((string)$attrs[$f]))) $m[]=$f; return $m; }
    private function row(Part $p,string $c,array $res,?MarketplaceListing $l,array $missing,array $attrs,array $before,array $after,string $action,?string $reason): array { return ['part_id'=>$p->id,'channel'=>$c,'marketplace'=>$attrs['marketplace'] ?? $c,'external_id'=>$res['id'],'current_id_link'=>$res,'existing_listing_id'=>$l?->id,'missing_fields'=>$missing,'planned_changes'=>Arr::only($attrs, ['marketplace','external_offer_id','external_listing_id','url','status','sync_status','match_status']),'planned_url'=>$attrs['url'] ?? null,'resolver_before'=>$before,'resolver_after'=>$after,'action'=>$action,'reason'=>$reason]; }
    private function summary(array $rows): array { return ['create'=>count(array_filter($rows, fn($r)=>$r['action']==='create')),'update'=>count(array_filter($rows, fn($r)=>$r['action']==='update')),'skip'=>count(array_filter($rows, fn($r)=>$r['action']==='skip')),'conflict'=>count(array_filter($rows, fn($r)=>$r['action']==='conflict'))]; }
}
