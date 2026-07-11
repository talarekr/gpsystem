<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Mail\WorkshopPartCreatedMail;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Services\Parts\PartImageUploadService;
use App\Support\Parts\AdditionalPartCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class WorkshopQuickPartController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->hasValidToken($request)) {
            abort(403);
        }

        return $this->renderForm($request);
    }

    public function createAuthenticated(Request $request): View
    {
        return $this->renderForm($request);
    }

    public function storageLocationAutocomplete(Request $request): JsonResponse
    {
        $search = StorageLocation::displayName((string) $request->query('q', ''));

        if (mb_strlen($search) < 3) {
            return response()->json(['data' => []]);
        }

        $locations = StorageLocation::query()
            ->where('is_active', true)
            ->where('name', 'like', '%'.$search.'%')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name'])
            ->map(fn (StorageLocation $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])
            ->values();

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request, PartImageUploadService $partImageUploadService): RedirectResponse
    {
        if (! $this->hasValidToken($request)) {
            abort(403);
        }

        return $this->storePart($request, $partImageUploadService, 'tools.workshop.quick-part-create', [
            'token' => $request->query('token'),
        ]);
    }

    public function storeAuthenticated(Request $request, PartImageUploadService $partImageUploadService): RedirectResponse
    {
        return $this->storePart($request, $partImageUploadService, 'workshop.quick-part-create');
    }

    private function storePart(
        Request $request,
        PartImageUploadService $partImageUploadService,
        string $redirectRoute,
        array $redirectParameters = []
    ): RedirectResponse
    {
        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'max:12288'],
            'storage_location' => ['required', 'string', 'max:255'],
            'storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'part_number' => ['required', 'string', 'max:255'],
            'additional_part_codes' => ['nullable', 'array', 'max:2'],
            'additional_part_codes.*' => ['nullable', 'string', 'max:255'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'condition_notes' => ['nullable', 'string', 'max:255'],
            'steering_side' => ['nullable', 'string', 'max:255'],
            'send_email_copy' => ['nullable', 'boolean'],
        ], [
            'photos.required' => 'Dodaj minimum jedno zdjęcie części.',
            'photos.min' => 'Dodaj minimum jedno zdjęcie części.',
            'photos.*.image' => 'Każdy plik musi być zdjęciem.',
            'photos.*.max' => 'Zdjęcie może mieć maksymalnie 12 MB.',
            'storage_location.required' => 'Podaj magazyn lub miejsce składowania.',
            'part_number.required' => 'Podaj główny kod części.',
            'additional_part_codes.max' => 'Można dodać maksymalnie 2 dodatkowe kody części.',
        ]);

        $part = DB::transaction(function () use ($request, $validated, $partImageUploadService): Part {
            $conditionNotes = $request->has('condition_notes')
                ? ($validated['condition_notes'] ?? null)
                : 'Używany';
            $steeringSide = $request->has('steering_side')
                ? ($validated['steering_side'] ?? null)
                : 'po lewej';

            $location = $this->resolveStorageLocation(
                StorageLocation::displayName($validated['storage_location']),
                isset($validated['storage_location_id']) ? (int) $validated['storage_location_id'] : null,
            );

            $additionalPartCodes = AdditionalPartCodes::normalize($validated['additional_part_codes'] ?? null, $validated['part_number']);

            $part = new Part([
                'name' => trim($validated['part_number']),
                'part_number' => trim($validated['part_number']),
                'additional_part_codes' => $additionalPartCodes,
                'storage_location_id' => $location->id,
                'description' => filled($validated['internal_note'] ?? null) ? trim($validated['internal_note']) : null,
                'condition_notes' => filled($conditionNotes) ? trim($conditionNotes) : null,
                'quantity' => 1,
                'status' => 'draft',
                'is_visible_storefront' => false,
                'needs_listing' => true,
                'needs_review' => false,
            ]);
            $part->skipCategorySuggestion = true;
            $part->save();

            if (filled($steeringSide)) {
                $part->forceFill(['vehicle_snapshot' => ['steering_side' => trim($steeringSide)]])->saveQuietly();
            }

            $partImageUploadService->attachUploadedImages(
                part: $part,
                uploadedFiles: $request->file('photos', []),
                sourceSystem: 'workshop_quick_create',
            );

            return $part;
        });

        $mailWarning = null;

        if ($request->boolean('send_email_copy')) {
            $mailWarning = $this->sendWorkshopNotification($part);
        }

        $redirect = redirect()
            ->route($redirectRoute, $redirectParameters)
            ->with('workshop_quick_part_created', [
                'id' => $part->id,
                'part_number' => $part->part_number,
                'admin_url' => url('/admin/parts/'.$part->id.'/edit'),
            ]);

        if ($mailWarning !== null) {
            $redirect->with('workshop_quick_part_mail_warning', $mailWarning);
        }

        return $redirect;
    }

    private function resolveStorageLocation(string $name, ?int $selectedLocationId): StorageLocation
    {
        if ($selectedLocationId !== null) {
            $selected = StorageLocation::query()->find($selectedLocationId);

            if ($selected !== null) {
                return $selected;
            }
        }

        $normalizedName = StorageLocation::normalizeName($name);

        $existing = StorageLocation::query()
            ->get()
            ->first(fn (StorageLocation $location): bool => StorageLocation::normalizeName($location->name) === $normalizedName);

        if ($existing !== null) {
            return $existing;
        }

        return StorageLocation::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function sendWorkshopNotification(Part $part): ?string
    {
        $recipient = config('services.workshop_intake.notification_email');

        if (blank($recipient)) {
            Log::warning('Workshop intake notification email is not configured.', [
                'part_id' => $part->id,
            ]);

            return null;
        }

        try {
            Mail::to($recipient)->send(new WorkshopPartCreatedMail(
                $part->loadMissing(['images', 'storageLocation'])
            ));
        } catch (\Throwable $exception) {
            Log::error('Workshop intake notification email failed.', [
                'part_id' => $part->id,
                'exception' => $exception,
            ]);

            return 'Część została dodana, ale nie udało się wysłać kopii e-mail';
        }

        return null;
    }

    private function renderForm(Request $request): View
    {
        $isAuthenticatedWorkshopRoute = $request->routeIs('workshop.quick-part-create');

        return view('tools.workshop-quick-part-create', [
            'token' => $request->query('token'),
            'part' => $request->session()->pull('workshop_quick_part_created'),
            'formAction' => $isAuthenticatedWorkshopRoute
                ? route('workshop.quick-part-create.store')
                : route('tools.workshop.quick-part-create.store', ['token' => $request->query('token')]),
            'createAnotherUrl' => $isAuthenticatedWorkshopRoute
                ? route('workshop.quick-part-create')
                : url('/tools/workshop/quick-part-create?token='.$request->query('token')),
        ]);
    }

    private function hasValidToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token'));
    }
}
