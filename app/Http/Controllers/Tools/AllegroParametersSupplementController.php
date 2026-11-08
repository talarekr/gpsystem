<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\AllegroParametersSupplement;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;

/** Owner-only backend endpoints; no changes to the existing export panel or its runner. */
final class AllegroParametersSupplementController extends Controller
{
    public function __invoke(Request $request, AllegroParametersSupplement $supplements, string $run)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['parent_attempt_id' => ['required', 'uuid'], 'action' => ['sometimes', 'in:start,tick'], 'download' => ['sometimes', 'string', 'max:40', 'regex:/\A(?:manifest|checksums|summary|archive-manifest|volume-[0-9]{6})\z/D']]);
        $owner = (int) $request->user()->id;
        try {
            if ($request->isMethod('post')) {
                abort_unless(isset($input['action']), 422);
                ignore_user_abort(true);
                $data = $input['action'] === 'start' ? $supplements->start($owner, $run, $input['parent_attempt_id']) : $supplements->tick($owner, $run, $input['parent_attempt_id']);
            } elseif (isset($input['download'])) {
                $path = $supplements->download($owner, $run, $input['parent_attempt_id'], $input['download']);
                $response = response()->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
                $response->setPrivate();
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

                return $response;
            } else {
                $data = $supplements->status($owner, $run, $input['parent_attempt_id']);
            }

            return response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
        } catch (MigrationExportException $exception) {
            return response()->json(['ok' => false, 'reason_code' => $exception->reasonCode], $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            $class = class_basename($exception);

            return response()->json(['ok' => false, 'reason_code' => 'supplement_server_exception', 'backend_exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) ? $class : 'AnonymousThrowable'], 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
