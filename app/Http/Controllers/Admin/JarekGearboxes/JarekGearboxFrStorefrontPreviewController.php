<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\JarekGearboxes\JarekGearboxFrStorefrontPreviewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JarekGearboxFrStorefrontPreviewController extends Controller
{
    public function __invoke(Request $request, JarekGearboxFrStorefrontPreviewService $preview): Response
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'format' => ['sometimes', 'in:html,json'],
            'download' => ['sometimes', 'boolean'],
        ]);
        $result = $preview->preview((int) ($input['limit'] ?? 50), (int) ($input['offset'] ?? 0));
        $json = ($input['format'] ?? null) === 'json' || $request->expectsJson() || $request->boolean('download');
        $response = $json ? response()->json($result) : response()->view('admin.jarek-gearboxes.fr-storefront-preview', ['preview' => $result]);
        if ($request->boolean('download')) {
            $response->header('Content-Disposition', 'attachment; filename="jarek-fr-storefront-preview.json"');
        }

        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, noarchive')->header('Referrer-Policy', 'no-referrer');
    }
}
