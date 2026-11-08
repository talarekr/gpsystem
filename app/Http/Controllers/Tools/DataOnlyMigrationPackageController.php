<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\DataOnlyMigrationPackage;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;

final class DataOnlyMigrationPackageController extends Controller
{
    public function __invoke(Request $request, DataOnlyMigrationPackage $packages, string $run)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['parent_attempt_id' => ['required', 'uuid'], 'action' => ['sometimes', 'in:start,tick'], 'download' => ['sometimes', 'string', 'max:80', 'regex:/\A(?:data-only-package-manifest|data-only-archive-manifest|volume-[0-9]{6})\z/D']]);
        $owner = (int) $request->user()->id;
        try {
            if ($request->isMethod('post')) {
                abort_unless(isset($input['action']), 422);
                ignore_user_abort(true);
                $data = $input['action'] === 'start' ? $packages->start($owner, $run, $input['parent_attempt_id']) : $packages->tick($owner, $run, $input['parent_attempt_id']);
            } elseif (isset($input['download'])) {
                $artifact = $packages->download($owner, $run, $input['parent_attempt_id'], $input['download']);
                $response = response()->download($artifact['path'], $artifact['name'], ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
                $response->setPrivate();
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

                return $response;
            } else {
                $data = $packages->status($owner, $run, $input['parent_attempt_id']);
            }

            return response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
        } catch (MigrationExportException $exception) {
            return response()->json(['ok' => false, 'reason_code' => $exception->reasonCode], $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            $class = class_basename($exception);

            return response()->json(['ok' => false, 'reason_code' => 'data_only_server_exception', 'backend_exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) ? $class : 'AnonymousThrowable'], 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
