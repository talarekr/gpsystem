<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use App\Services\Marketplace\PartMarketplaceReadinessService;
use App\Services\Marketplace\AllegroCompatibilitySuggestionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartMarketplaceReadinessController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    /**
     * Some imported marketplace trees (notably older eBay imports) mark root
     * categories with sentinel parent values instead of SQL NULL. Treat them
     * as roots when the drawer asks for the first lazy level.
     */
    private const ROOT_PARENT_EXTERNAL_CATEGORY_IDS = ['', '0', 'root', 'ROOT'];

    public function __construct(
        private readonly MarketplaceListingReadinessService $readinessService,
        private readonly PartMarketplaceReadinessService $cardReadinessService,
        private readonly AllegroCompatibilitySuggestionsService $compatibilitySuggestionsService,
    ) {}

    public function check(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $failedStage = 'load_part';

        try {
            $part = Part::query()->find((int) $request->query('part_id'));

            if (! $part) {
                return response()->json([
                    'ok' => false,
                    'blocker' => 'part_not_found',
                    'blockers' => ['part_not_found'],
                ], 404);
            }

            $failedStage = 'build_summary';
            $result = $this->readinessService->checkAll($part);

            return response()->json(['ok' => true, 'part_id' => $part->id, 'part_name' => $part->name] + $result);
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'), $failedStage);
        }
    }


    public function ebayPreview(Request $request): View|JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $failedStage = 'load_part';

        try {
            $data = $this->buildEbayPreviewData($request);

            return view('admin.marketplace.ebay-listing-preview', $data + [
                'htmlPreviewUrl' => route('tools.ebay-listing-preview-html', [
                    'token' => (string) $request->query('token'),
                    'part_id' => $data['part']->id,
                    'channel' => $data['channel'],
                ]),
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'), $failedStage);
        }
    }

    public function ebayPreviewHtml(Request $request): Response|JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $failedStage = 'load_part';

        try {
            $data = $this->buildEbayPreviewData($request);

            return response((string) $data['html'], 200)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Content-Security-Policy', "default-src 'none'; img-src https://gpswiss.pl data:; style-src 'unsafe-inline';")
                ->header('Referrer-Policy', 'no-referrer');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'), $failedStage);
        }
    }

    /** @return array{part: Part, channel: string, readiness: array<string, mixed>, preview: array<string, mixed>, html: string} */
    private function buildEbayPreviewData(Request $request): array
    {
        $part = Part::query()->find((int) $request->query('part_id'));

        if (! $part) {
            abort(response()->json([
                'ok' => false,
                'blocker' => 'part_not_found',
                'blockers' => ['part_not_found'],
            ], 404));
        }

        $channel = (string) $request->query('channel', 'ebay_de');

        if (! in_array($channel, ['ebay_de', 'ebay_fr'], true)) {
            $channel = 'ebay_de';
        }

        $readiness = $this->readinessService->checkPartReadiness($part, $channel);
        $preview = $readiness['prepared_payload_preview_safe'] ?? [];
        $preview['will_make_marketplace_request'] = false;

        return [
            'part' => $part,
            'channel' => $channel,
            'readiness' => $readiness,
            'preview' => $preview,
            'html' => (string) ($preview['description_rendered_html'] ?? ''),
        ];
    }

    public function prepareEbay(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $part = Part::query()->find((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['part_not_found']], 404);
        $channel = (string) $request->query('channel', 'ebay_de');
        $result = $this->readinessService->prepareEbayTranslations($part, $channel);
        return response()->json($result + ['part_id' => $part->id]);
    }


    public function prepareEbayAll(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $part = Part::query()->find((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['part_not_found']], 404);

        $results = [
            'ebay_de' => $this->readinessService->prepareEbayTranslations($part, 'ebay_de'),
        ];

        return response()->json([
            'ok' => collect($results)->every(fn (array $result): bool => (bool) ($result['ok'] ?? false)),
            'part_id' => $part->id,
            'message' => 'Aukcja przygotowana',
            'channels' => $results,
            'will_make_marketplace_request' => false,
            'publish' => false,
        ]);
    }



    public function prepareCard(Request $request): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $key = (string) $request->query('channel', 'allegro');

        try {
            if (! $this->validToken($request)) {
                if ($key === 'ebay') $this->logPrepareFailure($partId, $key, new \RuntimeException('Invalid diagnostics token.'), 403, 'local_app');
                return $this->invalidTokenResponse();
            }

            $part = Part::query()->find($partId);
            if (! $part) return response()->json(['ok' => false, 'ready' => false, 'message' => 'Nie znaleziono części.', 'blockers' => ['part_not_found']], 404);

            if (! in_array($key, ['allegro', 'ovoko', 'ebay'], true)) {
                return response()->json(['ok' => false, 'ready' => false, 'message' => 'Nieobsługiwany kanał sprzedaży.', 'blockers' => ['unsupported_channel']], 422);
            }

            if ($key === 'ebay') {
                Log::debug('Starting lightweight eBay prepare action.', ['part_id' => $part->id, 'channel' => 'ebay_de']);

                $ebayResults = [
                    'ebay_de' => $this->readinessService->prepareEbayTranslations($part, 'ebay_de'),
                ];

                $part = $this->freshPartForMarketplaceReadiness($part);

                if (! (bool) ($ebayResults['ebay_de']['ok'] ?? false)) {
                    $blockers = (array) ($ebayResults['ebay_de']['blockers'] ?? []);

                    return response()->json([
                        'ok' => false,
                        'ready' => false,
                        'status' => 'blocked',
                        'message' => (string) (($ebayResults['ebay_de']['blocker'] ?? null) ?: ($blockers[0] ?? 'translation_failed')),
                        'part_id' => $part->id,
                        'channel' => $key,
                        'will_make_marketplace_request' => false,
                        'publish' => false,
                        'marketplace_listings' => false,
                        'blocker' => $ebayResults['ebay_de']['blocker'] ?? ($blockers[0] ?? null),
                        'blockers' => $blockers,
                        'ebay_channels' => $ebayResults,
                    ]);
                }
            }

            $part = $this->freshPartForMarketplaceReadiness($part);
            $card = $this->cardReadinessService->check($part)[$key] ?? [];
            $presentation = (array) ($card['presentation'] ?? []);
            $ready = (bool) ($presentation['ready'] ?? $card['ready'] ?? false);
            $missingParams = (array) ($card['missing_required_allegro_parameters'] ?? []);
            if ($key === 'allegro') {
                $metadata = is_array($part->review_metadata) ? $part->review_metadata : [];
                data_set($metadata, 'marketplace_prepare_results.allegro.dynamic_allegro_parameters', $card['dynamic_allegro_parameters'] ?? null);
                data_set($metadata, 'marketplace_prepare_results.allegro.missing_required_allegro_parameters', $missingParams);
                data_set($metadata, 'marketplace_prepare_results.allegro.status', $ready ? 'ready' : 'blocked');
                $part->forceFill(['review_metadata' => $metadata])->save();
                if ($ready) {
                    $compatibilityResult = $this->compatibilitySuggestionsService->fetchAndStoreForPreparedPayload($part->fresh(), (array) ($card['prepared_payload_preview_safe'] ?? []));
                    $part = $this->freshPartForMarketplaceReadiness($part);
                }
            }
            $message = $ready ? 'Gotowe' : ($missingParams !== [] ? 'Uzupełnij wymagane parametry Allegro powyżej i zapisz produkt. Brakuje: '.implode(', ', array_values(array_filter(array_map(fn ($param) => is_array($param) ? ($param['name'] ?? null) : null, $missingParams)))) : $this->humanReadablePrepareMessage((array) ($presentation['missing'] ?? $card['missing'] ?? [])));

            return response()->json([
                'ok' => $ready,
                'ready' => $ready,
                'status' => $ready ? 'ready' : 'blocked',
                'message' => $message,
                'part_id' => $part->id,
                'channel' => $key,
                'will_make_marketplace_request' => false,
                'publish' => false,
                'marketplace_listings' => false,
                'missing_required_allegro_parameters' => $missingParams,
                'dynamic_allegro_parameters' => $card['dynamic_allegro_parameters'] ?? null,
                'prepared_payload_preview_safe' => $card['prepared_payload_preview_safe'] ?? null,
                'compatibility' => $compatibilityResult['compatibility'] ?? null,
                'compatibility_message' => $compatibilityResult['message'] ?? null,
                'ebay_channels' => $key === 'ebay' ? ($ebayResults ?? []) : null,
            ]);
        } catch (\Throwable $e) {
            if ($key === 'ebay') $this->logPrepareFailure($partId, $key, $e, $this->httpStatus($e), $this->failureSource($e));
            throw $e;
        }
    }

    private function freshPartForMarketplaceReadiness(Part $part): Part
    {
        return Part::query()
            ->with(array_values(array_filter([
                method_exists($part, 'images') ? 'images' : null,
                method_exists($part, 'partImages') ? 'partImages' : null,
                method_exists($part, 'category') ? 'category' : null,
                method_exists($part, 'car') ? 'car' : null,
                method_exists($part, 'marketplaceListings') ? (Schema::hasTable('marketplace_accounts') ? 'marketplaceListings.account' : 'marketplaceListings') : null,
            ])))
            ->findOrFail($part->getKey());
    }


    public function allegroCompatibilityAudit(Request $request, Part $part): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole([\App\Enums\UserRole::OwnerAdmin->value]), 403);
        $prepared = (array) data_get((array) $this->readinessService->checkPartReadiness($this->freshPartForMarketplaceReadiness($part), 'allegro_main'), 'prepared_payload_preview_safe', []);
        return response()->json($this->compatibilitySuggestionsService->audit($part->fresh(), $prepared));
    }

    public function allegroCompatibilityPreview(Request $request, Part $part): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole([\App\Enums\UserRole::OwnerAdmin->value]), 403);
        $prepared = (array) data_get((array) $this->readinessService->checkPartReadiness($this->freshPartForMarketplaceReadiness($part), 'allegro_main'), 'prepared_payload_preview_safe', []);
        return response()->json($this->compatibilitySuggestionsService->preview($part->fresh(), $prepared));
    }

    public function ebayPrepareDebug(Request $request, int $partId): JsonResponse
    {
        $channel = (string) $request->query('channel', 'ebay_de');
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;
        $checks = [];
        $blockers = [];

        $part = Part::query()->with(['category'])->find($partId);
        $checks[] = ['name' => 'part_exists', 'ok' => (bool) $part];
        if (! $part) $blockers[] = 'part_not_found';

        $account = $this->marketplaceAccount($channel);
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $checks[] = ['name' => 'channel_account_configured', 'ok' => (bool) $account, 'status' => $account?->status, 'api_enabled' => $account?->api_enabled];
        if (! $account) $blockers[] = 'marketplace_account_missing';

        $tokenExpiry = $credentials['access_token_expires_at'] ?? $credentials['expires_at'] ?? null;
        $checks[] = ['name' => 'token_exists', 'ok' => filled($credentials['access_token'] ?? null) || filled($credentials['refresh_token'] ?? null), 'access_token_configured' => filled($credentials['access_token'] ?? null), 'refresh_token_configured' => filled($credentials['refresh_token'] ?? null), 'expiry' => $tokenExpiry];
        if (blank($credentials['access_token'] ?? null) && blank($credentials['refresh_token'] ?? null)) $blockers[] = 'ebay_token_missing';

        $mapping = $part ? $this->ebayCategoryMappingForPart($part, $channel) : null;
        $checks[] = ['name' => 'local_category', 'ok' => filled($part?->category_id), 'category_id' => $part?->category_id, 'category_name' => $part?->category?->name];
        $checks[] = ['name' => 'ebay_category_mapping', 'ok' => (bool) $mapping && ! (bool) ($mapping?->is_blocked), 'external_category_id' => $mapping?->external_category_id, 'external_category_name' => $mapping?->external_category_name, 'is_blocked' => $mapping?->is_blocked];
        if ($part && ! $mapping) $blockers[] = 'ebay_category_mapping_missing';
        if ($mapping?->is_blocked) $blockers[] = 'ebay_category_mapping_blocked';

        foreach (['payment_policy_id', 'fulfillment_policy_id', 'return_policy_id'] as $key) {
            $value = $key === 'fulfillment_policy_id' ? ($mapping?->fulfillment_policy_id ?: data_get($settings, $key)) : data_get($settings, $key);
            $checks[] = ['name' => $key, 'ok' => filled($value), 'value' => filled($value) ? (string) $value : null];
            if (blank($value)) $blockers[] = $key.'_missing';
        }

        $checks[] = ['name' => 'shipping_group', 'ok' => filled($mapping?->shipping_group), 'value' => $mapping?->shipping_group];
        if (blank($mapping?->shipping_group)) $blockers[] = 'shipping_group_missing';

        $readiness = $part ? $this->readinessService->checkPartReadiness($part, $channel) : [];
        $prepareBlockers = (array) ($readiness['blockers'] ?? []);
        $blockers = array_values(array_unique(array_filter(array_merge($blockers, $prepareBlockers))));

        return response()->json([
            'ok' => $blockers === [],
            'dry_run' => true,
            'marketplace_write' => false,
            'part_id' => $partId,
            'channel' => $channel,
            'prepare_action_class' => self::class,
            'prepare_action_method' => 'prepareCard',
            'checks' => $checks,
            'blockers' => $blockers,
            'likely_cause' => $blockers === [] ? 'prepare_action_is_local_only; if UI returns 403, likely local_app route/auth/token before eBay API' : $blockers[0],
            'recommendations' => $this->prepareDebugRecommendations($blockers),
            'prepare_will_make_marketplace_request' => false,
            'readiness' => ['can_prepare' => $readiness['can_prepare'] ?? null, 'missing_fields' => $readiness['missing_fields'] ?? [], 'warnings' => $readiness['warnings'] ?? []],
        ]);
    }

    public function categoryChildren(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $data = $request->validate([
            'channel' => ['required', 'in:allegro_main,ovoko,ebay_de,ebay_fr'],
            'parent_external_category_id' => ['nullable', 'string', 'max:255'],
        ]);

        $channel = (string) $data['channel'];
        $parentId = $data['parent_external_category_id'] ?? null;

        $rootMode = ! filled($parentId);

        $query = MarketplaceCategory::query()
            ->where('channel', $channel)
            ->when(
                ! $rootMode,
                fn ($query) => $query->where('parent_external_category_id', (string) $parentId),
                fn ($query) => $query->where(function ($query): void {
                    $query->whereNull('parent_external_category_id')
                        ->orWhereIn('parent_external_category_id', self::ROOT_PARENT_EXTERNAL_CATEGORY_IDS);
                })
            )
            ->orderBy('name')
            ->orderBy('external_category_id');

        $children = $query->get(['external_category_id', 'parent_external_category_id', 'name', 'full_path']);
        $childIds = $children->pluck('external_category_id')->map(fn ($id): string => (string) $id)->all();
        $parentsWithChildren = $childIds === []
            ? collect()
            : MarketplaceCategory::query()
                ->where('channel', $channel)
                ->whereIn('parent_external_category_id', $childIds)
                ->select('parent_external_category_id')
                ->distinct()
                ->pluck('parent_external_category_id')
                ->mapWithKeys(fn ($id): array => [(string) $id => true]);

        return response()->json([
            'ok' => true,
            'channel' => $channel,
            'parent_external_category_id' => filled($parentId) ? (string) $parentId : null,
            'root_mode' => $rootMode,
            'count' => $children->count(),
            'children' => $children->map(fn (MarketplaceCategory $category): array => [
                'id' => (string) $category->external_category_id,
                'parent_id' => filled($category->parent_external_category_id) && ! in_array((string) $category->parent_external_category_id, self::ROOT_PARENT_EXTERNAL_CATEGORY_IDS, true) ? (string) $category->parent_external_category_id : null,
                'name' => $category->name ?: ($category->full_path ?: $category->external_category_id),
                'path' => $category->full_path ?: ($category->name ?: $category->external_category_id),
                'full_slug_path' => $category->full_path,
                'has_children' => (bool) ($parentsWithChildren[(string) $category->external_category_id] ?? false),
            ])->values(),
            'source' => 'local_db_only',
            'will_make_marketplace_request' => false,
            'publish' => false,
        ]);
    }


    public function partCategoryChildren(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:part_categories,id'],
            'q' => ['nullable', 'string', 'min:2', 'max:120'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));
        $parentId = $data['parent_id'] ?? null;

        $query = PartCategory::query()
            ->select(['id', 'parent_id', 'name', 'category_path', 'full_slug_path', 'sort_order', 'woo_product_count'])
            ->where(function ($query): void {
                $query->whereNull('name')
                    ->orWhereRaw('LOWER(TRIM(name)) <> ?', ['bez kategorii']);
            });

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';

            $query->where(function ($query) use ($like): void {
                $query->whereRaw('LOWER(name) like ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(category_path, \'\')) like ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(full_slug_path, \'\')) like ?', [$like]);
            })->limit(25);
        } else {
            filled($parentId)
                ? $query->where('parent_id', (int) $parentId)
                : $query->whereNull('parent_id');
        }

        $children = $query->ordered()->get();
        $childIds = $children->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $parentsWithChildren = $childIds === []
            ? collect()
            : PartCategory::query()
                ->whereIn('parent_id', $childIds)
                ->select('parent_id')
                ->distinct()
                ->pluck('parent_id')
                ->mapWithKeys(fn ($id): array => [(string) $id => true]);

        return response()->json([
            'ok' => true,
            'parent_id' => filled($parentId) ? (int) $parentId : null,
            'search' => $search !== '',
            'count' => $children->count(),
            'children' => $children->map(fn (PartCategory $category): array => [
                'id' => (int) $category->id,
                'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
                'name' => $category->name,
                'path' => $category->category_path ?: ($category->full_slug_path ?: $category->name),
                'full_slug_path' => $category->full_slug_path,
                'woo_product_count' => $category->woo_product_count,
                'has_children' => (bool) ($parentsWithChildren[(string) $category->id] ?? false),
            ])->values(),
            'source' => 'local_db_only',
            'will_make_marketplace_request' => false,
            'publish' => false,
        ]);
    }

    public function storeCategoryMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'channel' => ['required', 'in:allegro_main,ovoko,ebay_de,ebay_fr'],
            'external_category_id' => ['required', 'string', 'max:255'],
        ]);

        $part = Part::query()->findOrFail((int) $data['part_id']);
        $category = MarketplaceCategory::query()
            ->where('channel', $data['channel'])
            ->where('external_category_id', $data['external_category_id'])
            ->firstOrFail();

        $overrideKey = match ($data['channel']) {
            'allegro_main' => 'allegro',
            'ovoko' => 'ovoko',
            'ebay_de', 'ebay_fr' => 'ebay',
        };

        $metadata = (array) ($part->review_metadata ?: []);
        $metadata['marketplace_category_overrides'] ??= [];
        $metadata['marketplace_category_overrides'][$overrideKey] = [
            'channel' => $data['channel'],
            'external_category_id' => (string) $category->external_category_id,
            'external_category_name' => $category->name,
            'external_category_path' => $category->full_path,
            'source' => 'manual_part_edit_marketplace_preparation',
            'selected_at' => now()->toISOString(),
        ];

        $part->forceFill(['review_metadata' => $metadata])->save();

        return back()->with('status', 'Ręczna kategoria marketplace zapisana lokalnie dla tej części.');
    }

    public function payload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $failedStage = 'load_part';

        try {
            $part = Part::query()->find((int) $request->query('part_id'));

            if (! $part) {
                return response()->json([
                    'ok' => false,
                    'blocker' => 'part_not_found',
                    'blockers' => ['part_not_found'],
                ], 404);
            }

            $channel = (string) $request->query('channel', 'allegro_main');
            $failedStage = $this->failedStageForChannel($channel);
            $readiness = $this->readinessService->checkPartReadiness($part, $channel);

            $failedStage = 'payload_preview';

            return response()->json([
                'ok' => true,
                'part_id' => $part->id,
                'part_name' => $part->name,
                'channel' => $readiness['channel'],
                'payload_preview_safe' => $readiness['prepared_payload_preview_safe'],
                'readiness' => $readiness,
            ]);
        } catch (\Throwable $e) {
            return $this->safeExceptionResponse($e, (int) $request->query('part_id'), $failedStage);
        }
    }


    /** @param array<int, string> $messages */
    private function humanReadablePrepareMessage(array $messages): string
    {
        $message = (string) ($messages[0] ?? '');

        return match ($message) {
            'cena', 'cena Allegro', 'cena Ovoko' => 'Uzupełnij cenę',
            'cena eBay' => 'Uzupełnij cenę eBay',
            'mapowanie kategorii Allegro', 'mapowanie kategorii Ovoko', 'mapowanie kategorii eBay' => 'Wybierz kategorię',
            'allegro_required_category_parameters_missing' => 'Brakuje wymaganych parametrów Allegro',
            'prepared_translations', 'tłumaczenie eBay DE', 'Brak przygotowanego tłumaczenia eBay DE' => 'Brak przygotowanego tłumaczenia eBay DE',
            'tłumaczenie eBay FR', 'Brak przygotowanego tłumaczenia eBay FR' => 'Brak przygotowanego tłumaczenia eBay FR',
            'category_shipping_group', 'Brak grupy wysyłkowej dla kategorii' => 'Brak grupy wysyłkowej dla kategorii',
            'shipping_policy_mapping', 'Brak mapowania polityki wysyłki' => 'Brak mapowania polityki wysyłki',
            default => filled($message) ? $message : 'Wymaga uzupełnienia',
        };
    }

    private function logPrepareFailure(int $partId, string $channel, \Throwable $e, ?int $httpStatus, string $source): void
    {
        $payload = [
            'action' => 'ebay_prepare_failed',
            'part_id' => $partId ?: null,
            'channel' => $channel === 'ebay' ? 'ebay_de' : $channel,
            'user_id' => Auth::id(),
            'class' => self::class,
            'method' => 'prepareCard',
            'source' => $source,
            'exception_class' => $e::class,
            'message' => $this->safeExceptionMessage($e),
            'code' => $e->getCode(),
            'http_status' => $httpStatus,
            'endpoint_path' => $this->endpointPath($e),
            'sanitized_response_body' => $this->sanitizedResponseBody($e),
            'trace_first_3' => array_slice(array_map(fn (array $frame): array => ['file' => $frame['file'] ?? null, 'line' => $frame['line'] ?? null, 'function' => $frame['function'] ?? null, 'class' => $frame['class'] ?? null], $e->getTrace()), 0, 3),
        ];

        try {
            MarketplaceSyncLog::query()->create(['marketplace' => 'ebay', 'part_id' => $partId ?: null, 'action' => 'ebay_prepare_failed', 'status' => 'failed', 'http_status' => $httpStatus, 'message' => $payload['message'], 'payload' => $payload, 'created_at' => now()]);
        } catch (\Throwable) {
            Log::warning('ebay_prepare_failed', $payload);
        }
    }

    private function marketplaceAccount(string $channel): ?MarketplaceAccount { return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null; }
    private function ebayCategoryMappingForPart(Part $part, string $channel): ?MarketplaceCategoryMapping { return Schema::hasTable('marketplace_category_mappings') && $part->category_id ? MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->where('channel', $channel)->first() : null; }
    private function httpStatus(\Throwable $e): ?int { return method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : ($e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : null); }
    private function failureSource(\Throwable $e): string { $message = strtolower($e->getMessage()); return str_contains($message, 'ebay') ? 'ebay_api' : ($this->httpStatus($e) ? 'local_app' : 'unknown'); }
    private function endpointPath(\Throwable $e): ?string { preg_match('#https?://[^/]+([^\s?]+)#', $e->getMessage(), $m); return $m[1] ?? null; }
    private function sanitizedResponseBody(\Throwable $e): ?string { return Str::limit($this->safeExceptionMessage($e), 1000, '...'); }
    private function prepareDebugRecommendations(array $blockers): array { if ($blockers === []) return ['Open /tools/prepare-part-marketplace-card with the generated token and inspect Network status; this prepare path is local and should not write to eBay.']; return array_map(fn (string $blocker): string => 'Resolve blocker: '.$blocker, $blockers); }

    private function validToken(Request $request): bool
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', ''));
    }

    private function invalidTokenResponse(): JsonResponse
    {
        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    private function safeExceptionResponse(\Throwable $e, int $partId, string $failedStage): JsonResponse
    {
        Log::warning('Part marketplace readiness diagnostics failed.', [
            'part_id' => $partId,
            'exception' => $e::class,
            'failed_stage' => $failedStage,
        ]);

        return response()->json([
            'ok' => false,
            'error_message_safe' => 'Marketplace readiness diagnostics could not be completed safely.',
            'blockers' => ['readiness_diagnostics_exception'],
            'exception_class' => $e::class,
            'exception_message_safe' => $this->safeExceptionMessage($e),
            'failed_stage' => $failedStage,
            'part_id' => $partId,
        ], 200);
    }

    private function failedStageForChannel(string $channel): string
    {
        return match ($channel === 'ebay' ? 'ebay_de' : $channel) {
            'storefront' => 'storefront_readiness',
            'allegro_main' => 'allegro_readiness',
            'ovoko' => 'ovoko_readiness',
            'ebay_de' => 'ebay_de_readiness',
            'ebay_fr' => 'ebay_fr_readiness',
            default => 'channel_readiness',
        };
    }

    private function safeExceptionMessage(\Throwable $e): string
    {
        return Str::limit(preg_replace(
            [
                '/([?&](?:token|api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|client[_-]?secret|credential)[^=]*=)[^&\s]+/i',
                '/\b(?:token|api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|client[_-]?secret|credential)\b\s*[:=]\s*[^\s,;]+/i',
            ],
            ['$1[redacted]', '[redacted_secret]'],
            $e->getMessage()
        ), 500, '...');
    }
}
