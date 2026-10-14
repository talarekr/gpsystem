<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\JarekFailedTranslationPreview;
use Illuminate\Http\Request;

class JarekFailedTranslationController extends Controller
{
    public function __invoke(Request $request, FrenchCatalogTranslationAdminRunner $runner, JarekFailedTranslationPreview $preview)
    {
        $input = $request->validate(['action' => ['nullable', 'in:preview,start,tick,pause,resume,stop'],
            'filter' => ['nullable', 'in:all,technical_code_changed,google_translate_failed'],
            'preview_id' => ['nullable', 'string', 'max:4096'], 'confirm' => ['nullable', 'string', 'max:64'],
            'run_id' => ['nullable', 'string', 'max:128']]);
        $runner = $runner->forScope('jarek_failed');
        if ($request->isMethod('GET')) {
            $report = ($input['action'] ?? null) === 'preview'
                ? $preview->preview((int) $request->user()->id, $input['filter'] ?? 'all')
                : $preview->publicReport($input['filter'] ?? 'all');
            $status = $runner->status();
            $report['manual_review_count'] = ($status['status'] ?? null) === 'completed' ? $report['total_failed'] : ($status['technical_retry_failed'] ?? 0);

            return response()->json(['ok' => true, 'run' => $status, 'report' => $report])->header('Cache-Control', 'private, no-store');
        }
        $action = $input['action'] ?? '';
        $result = match ($action) {
            'start' => $runner->startFailed((int) $request->user()->id, $input['preview_id'] ?? '', $input['confirm'] ?? ''),
            'tick' => $runner->tick($input['run_id'] ?? ''),
            'pause', 'resume', 'stop' => $runner->control($input['run_id'] ?? '', $action),
            default => ['ok' => false, 'reason' => 'invalid_action'],
        };
        $ok = $result['ok'] ?? false;
        $report = $preview->publicReport($input['filter'] ?? 'all');
        $report['manual_review_count'] = ($result['status'] ?? null) === 'completed' ? $report['total_failed'] : ($result['technical_retry_failed'] ?? 0);

        return response()->json(['ok' => $ok, 'run' => $result, 'report' => $report],
            $ok ? 200 : (in_array($result['reason'] ?? '', ['active_run', 'busy', 'control_conflict'], true) ? 409 : 422))->header('Cache-Control', 'private, no-store');
    }
}
