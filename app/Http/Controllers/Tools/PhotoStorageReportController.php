<?php

namespace App\Http\Controllers\Tools;

use App\Services\Tools\PhotoStorageReportService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PhotoStorageReportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, PhotoStorageReportService $reports)
    {
        $requestToken = (string) $request->query('token', '');

        if ($requestToken === '' || ! hash_equals(self::TOKEN, $requestToken)) {
            abort(403);
        }

        return response()->json(
            $reports->report(),
            200,
            [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
