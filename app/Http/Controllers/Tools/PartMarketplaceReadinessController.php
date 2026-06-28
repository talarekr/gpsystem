<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly MarketplaceListingReadinessService $readinessService) {}

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
            'ebay_fr' => $this->readinessService->prepareEbayTranslations($part, 'ebay_fr'),
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
