<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Services\Parts\PartImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkshopQuickPartController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->hasValidToken($request)) {
            abort(403);
        }

        return view('tools.workshop-quick-part-create', [
            'token' => $request->query('token'),
            'part' => $request->session()->pull('workshop_quick_part_created'),
        ]);
    }

    public function store(Request $request, PartImageUploadService $partImageUploadService): RedirectResponse
    {
        if (! $this->hasValidToken($request)) {
            abort(403);
        }

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'max:12288'],
            'storage_location' => ['required', 'string', 'max:255'],
            'part_number' => ['required', 'string', 'max:255'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ], [
            'photos.required' => 'Dodaj minimum jedno zdjęcie części.',
            'photos.min' => 'Dodaj minimum jedno zdjęcie części.',
            'photos.*.image' => 'Każdy plik musi być zdjęciem.',
            'photos.*.max' => 'Zdjęcie może mieć maksymalnie 12 MB.',
            'storage_location.required' => 'Podaj magazyn lub miejsce składowania.',
            'part_number.required' => 'Podaj główny kod części.',
        ]);

        $part = DB::transaction(function () use ($request, $validated, $partImageUploadService): Part {
            $location = StorageLocation::query()->firstOrCreate(
                ['name' => trim($validated['storage_location'])],
                ['is_active' => true]
            );

            $part = Part::query()->create([
                'name' => 'Część do wystawienia '.$validated['part_number'],
                'part_number' => trim($validated['part_number']),
                'storage_location_id' => $location->id,
                'description' => filled($validated['internal_note'] ?? null) ? trim($validated['internal_note']) : null,
                'quantity' => 1,
                'status' => 'draft',
                'is_visible_storefront' => false,
                'needs_listing' => true,
                'needs_review' => false,
            ]);

            $partImageUploadService->attachUploadedImages(
                part: $part,
                uploadedFiles: $request->file('photos', []),
                sourceSystem: 'workshop_quick_create',
            );

            return $part;
        });

        return redirect()
            ->route('tools.workshop.quick-part-create', ['token' => $request->query('token')])
            ->with('workshop_quick_part_created', [
                'id' => $part->id,
                'part_number' => $part->part_number,
                'admin_url' => url('/admin/parts/'.$part->id.'/edit'),
            ]);
    }

    private function hasValidToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token'));
    }
}
