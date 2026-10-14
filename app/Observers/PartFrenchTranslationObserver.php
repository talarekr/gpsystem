<?php

namespace App\Observers;

use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchProductTranslationRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartFrenchTranslationObserver
{
    public function created(Part $part): void
    {
        $this->refreshCategoryTree($part);
        $this->register($part);
    }

    public function updated(Part $part): void
    {
        if ($part->wasChanged(['category_id', 'status', 'needs_listing', 'needs_review', 'price', 'quantity'])) {
            $this->refreshCategoryTree($part);
        }
        if ($part->wasChanged($part->translationSourceFields())) {
            $this->register($part);
        }
    }

    private function refreshCategoryTree(Part $part): void
    {
        try {
            Cache::forget(CategoryTreeService::CACHE_KEY);
        } catch (Throwable $exception) {
            Log::warning('storefront_category_cache_invalidation_failed', ['part_id' => $part->id, 'exception_type' => $exception::class]);
        }
    }

    private function register(Part $part): void
    {
        try {
            app(FrenchProductTranslationRequests::class)->register($part);
        } catch (Throwable $exception) {
            // Do not fail the product save for optional translation tracking; no source/provider secrets.
            Log::warning('fr_product_translation_registration_failed', ['part_id' => $part->id, 'exception_type' => $exception::class]);
        }
    }
}
