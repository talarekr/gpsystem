<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\GpswissMigrationExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GpswissMigrationExportController extends Controller
{
    public function __invoke(Request $request, GpswissMigrationExport $exports, ?string $run = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $owner = (int) $request->user()->id;
        $input = $request->validate(['action' => ['sometimes', 'in:start,tick'], 'download' => ['sometimes', 'in:sample,full']]);
        if ($request->isMethod('post')) {
            abort_unless(isset($input['action']), 422);
            try {
                if ($input['action'] === 'start') {
                    $data = $exports->start($owner, $run);
                } else {
                    abort_unless($run, 422);
                    $data = $exports->tick($owner, $run);
                }

                return response()->json($data)->header('Cache-Control', 'private, no-store');
            } catch (HttpExceptionInterface $e) {
                throw $e;
            } catch (\RuntimeException) {
                return response()->json(['ok' => false, 'reason_code' => 'export_unavailable_check_private_storage_and_zip'], 409)->header('Cache-Control', 'private, no-store');
            }
        }
        if ($run && isset($input['download'])) {
            return response()->download($exports->download($owner, $run, $input['download']), 'gpswiss-wystawczesc-'.$input['download'].'-'.$run.'.zip', ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
        }

        return ($run ? response()->json($exports->status($owner, $run)) : response()->view('admin.tools.gpswiss-migration-export'))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
    }
}
