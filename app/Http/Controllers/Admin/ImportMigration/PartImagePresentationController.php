<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Images\PartImagePresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PartImagePresentationController extends Controller
{
    public function process(Request $request, Part $part, PartImagePresentationService $service): RedirectResponse
    {
        $processed = 0;

        foreach ($part->images as $image) {
            if (! $image->path) {
                continue;
            }

            $image->legacy_payload = $service->process($image);
            $image->saveQuietly();
            $processed++;
        }

        return back()->with('status', "Przetworzono zdjęcia produktu: {$processed}.");
    }
}
