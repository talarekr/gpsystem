<?php

namespace App\Http\Controllers\Tools;

use App\Services\Marketplace\EbayDescriptionTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EbayDescriptionTemplateController
{
    public function checkAssets(Request $request, EbayDescriptionTemplateService $service): JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        return response()->json($service->checkAssets());
    }

    public function syncAssetsDryRun(Request $request, EbayDescriptionTemplateService $service): JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        return response()->json($service->syncAssets(false));
    }

    public function syncAssets(Request $request, EbayDescriptionTemplateService $service): JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'blockers' => ['Missing confirm=1.'], 'warnings' => []], 400);
        return response()->json($service->syncAssets(true));
    }

    public function preview(Request $request, EbayDescriptionTemplateService $service): JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        return response()->json($service->preview((int) $request->query('part_id', 0), (string) $request->query('channel', 'ebay_de')));
    }

    public function previewHtml(Request $request, EbayDescriptionTemplateService $service): Response|JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        $preview = $service->preview((int) $request->query('part_id', 0), (string) $request->query('channel', 'ebay_de'));
        $banner = '<div style="padding:12px 16px;background:#fff3cd;border:1px solid #ffe69c;color:#664d03;font-family:Arial,sans-serif;margin:0 0 16px;">LOCAL EBAY TEMPLATE PREVIEW ONLY — not published on eBay, no eBay API calls, would_save=false.</div>';
        return response($banner.($preview['listing_description_html'] ?? ''), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function checkTemplate(Request $request, EbayDescriptionTemplateService $service): JsonResponse
    {
        if (! $this->validToken($request, $service)) return $this->invalidToken();
        return response()->json($service->validate((int) $request->query('part_id', 0), (string) $request->query('channel', 'ebay_de')));
    }

    private function validToken(Request $request, EbayDescriptionTemplateService $service): bool
    {
        return hash_equals($service->token(), (string) $request->query('token', ''));
    }

    private function invalidToken(): JsonResponse
    {
        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
