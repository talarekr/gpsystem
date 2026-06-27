<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
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

        MarketplaceCategoryMapping::query()->updateOrCreate(
            ['local_category_id' => $part->category_id, 'channel' => $data['channel']],
            [
                'external_category_id' => $category->external_category_id,
                'external_category_name' => $category->name,
                'external_category_path' => $category->full_path,
                'local_category_name' => $part->category?->name,
                'local_category_path' => $part->category?->full_slug_path ?: $part->category?->name,
                'source' => 'manual_part_edit_marketplace_preparation',
                'confidence' => 1,
                'is_blocked' => false,
                'imported_at' => now(),
            ]
        );

        return back()->with('status', 'Kategoria marketplace zapisana lokalnie.');
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
