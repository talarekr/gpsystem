<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\BuildAllegroMappingsFromPartsService;

class RunAllegroMappingLiveController extends Controller
{
    public function __invoke(BuildAllegroMappingsFromPartsService $service)
    {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) return response()->json(['ok'=>false,'error_message'=>'Invalid diagnostics token.'], 403);
        if (! hash_equals('build-allegro-mappings', (string) request()->query('confirm', ''))) return response()->json(['ok'=>false,'error_message'=>'Missing or invalid confirm parameter.'], 403);
        try {
            $summary = $service->run(false);
            return response()->json(['ok'=>true,'dry_run'=>false,'parts_total'=>$summary['parts_total'],'with_allegro_id'=>$summary['with_allegro_id'],'unique_allegro_ids'=>$summary['unique_allegro_ids'],'duplicates_count'=>$summary['duplicates_count'],'without_allegro_id'=>$summary['without_allegro_id'],'created'=>$summary['would_create'],'updated'=>$summary['would_update'],'conflicts'=>$summary['would_conflict'],'skipped'=>$summary['would_skip'],'samples_created'=>$summary['sample_create'],'samples_conflict'=>$summary['sample_conflict']]);
        } catch (\Throwable $exception) { return response()->json(['ok'=>false,'dry_run'=>false,'exception_class'=>$exception::class,'exception_message'=>$exception->getMessage(),'file'=>$exception->getFile(),'line'=>$exception->getLine()], 200); }
    }
}
