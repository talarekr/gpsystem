<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayConnectionCoverageService;
use Illuminate\Http\JsonResponse;

class EbayConnectionCoverageController extends Controller
{
    public function __invoke(EbayConnectionCoverageService $coverage): JsonResponse
    {
        return response()->json($coverage->report());
    }
}
