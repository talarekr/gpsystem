<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\BuildEbayMappingsFromPartsService;

class RunEbayMappingDryRunController extends Controller
{
    public function __invoke(BuildEbayMappingsFromPartsService $service)
    {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) return response()->json(['ok'=>false,'error_message'=>'Invalid diagnostics token.'], 403);
        try { return response()->json($service->run(true)); }
        catch (\Throwable $exception) { return response()->json(['ok'=>false,'dry_run'=>true,'exception_class'=>$exception::class,'exception_message'=>$exception->getMessage(),'file'=>$exception->getFile(),'line'=>$exception->getLine()], 200); }
    }
}
