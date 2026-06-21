<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\MarkGpsGmailPartsToListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MarkGpsGmailToListLiveController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, MarkGpsGmailPartsToListService $service): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        try {
            return response()->json($service->live((string) $request->query('confirm', '')));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['ok' => false, 'error_message' => $exception->getMessage()], 422);
        }
    }
}
