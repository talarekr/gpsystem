<?php

namespace App\Services\Storefront;

use App\Models\FrenchProductTranslationRequest;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Only local tracking; no provider resolution, queue dispatch, price or stock writes. */
class FrenchProductTranslationRequests
{
    public function ready(): bool
    {
        return Schema::hasTable('storefront_fr_product_translation_requests') && Schema::hasTable('part_translations');
    }

    public function register(Part $part): void
    {
        if (! $this->ready()) {
            return;
        }
        DB::transaction(function () use ($part): void {
            $fresh = Part::query()->whereKey($part->id)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }
            $hash = $fresh->translationSourceHash();
            $translation = $fresh->translations()->where('locale', 'fr')->lockForUpdate()->first();
            $current = $translation && $translation->matchesSourceHash($hash);
            $status = $translation?->isReady() && $current ? 'done' : ($translation?->status === 'reviewed' ? 'needs_review' : 'pending');
            // Queued claims are owned by the existing translator; it detects changes before commit.
            if ($translation?->status === 'translated' && ! $current) {
                $translation->update(['status' => 'needs_update']);
            }
            FrenchProductTranslationRequest::query()->updateOrCreate(['part_id' => $part->id], [
                'source_hash' => $hash, 'status' => $status, 'error_code' => null, 'requested_at' => now(), 'finished_at' => $status === 'done' ? now() : null,
            ]);
        });
    }

    public function finish(int $id, string $hash, array $result): void
    {
        $state = match ($result['status'] ?? 'failed') {
            'translated' => 'done', 'failed' => 'failed', default => match ($result['reason'] ?? '') {
                'current' => 'done', 'reviewed_protected' => 'needs_review', default => 'pending'
            },
        };
        FrenchProductTranslationRequest::query()->where('part_id', $id)->where('source_hash', $hash)->update([
            'status' => $state, 'error_code' => $result['reason'] ?? null, 'finished_at' => $state === 'done' ? now() : null, 'updated_at' => now(),
        ]);
    }
}
