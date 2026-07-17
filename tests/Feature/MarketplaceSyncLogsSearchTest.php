<?php

namespace Tests\Feature;

use App\Filament\Pages\Marketplace\MarketplaceSyncLogs;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceSyncLogsSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_related_part_id_search_matches_exact_id_variants(): void
    {
        Part::query()->create(['id' => 8071, 'name' => 'Test part 8071']);
        Part::query()->create(['id' => 18071, 'name' => 'Test part 18071']);

        $matched = MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'part_id' => 8071, 'action' => 'publish', 'status' => 'success', 'message' => 'Related part log', 'created_at' => now()]);
        MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'part_id' => 18071, 'action' => 'publish', 'status' => 'success', 'message' => 'Different part log', 'created_at' => now()]);
        MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'action' => 'publish', 'status' => 'success', 'message' => 'Log without relation', 'created_at' => now()]);

        foreach (['8071', '#8071', 'Part 8071'] as $search) {
            $this->assertSame([$matched->id], $this->relatedSearchIds($search));
        }
    }

    public function test_related_id_search_does_not_match_partial_ids(): void
    {
        Part::query()->create(['id' => 8071, 'name' => 'Test part 8071']);
        MarketplaceSyncLog::query()->create(['marketplace' => 'allegro', 'part_id' => 8071, 'action' => 'publish', 'status' => 'success', 'message' => 'Related part log', 'created_at' => now()]);

        $this->assertSame([], $this->relatedSearchIds('807'));
        $this->assertSame([], $this->relatedSearchIds('18071'));
    }

    public function test_existing_text_search_fields_still_match_logs(): void
    {
        $log = MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'action' => 'stock_sync', 'status' => 'success', 'message' => 'Needle text still searchable', 'created_at' => now()]);

        $ids = MarketplaceSyncLog::query()
            ->where(fn ($query) => $query
                ->where('marketplace', 'like', '%Needle%')
                ->orWhere('action', 'like', '%Needle%')
                ->orWhere('message', 'like', '%Needle%'))
            ->pluck('id')
            ->all();

        $this->assertSame([$log->id], $ids);
    }

    private function relatedSearchIds(string $search): array
    {
        return MarketplaceSyncLogs::applyRelatedSearch(MarketplaceSyncLog::query(), $search)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
