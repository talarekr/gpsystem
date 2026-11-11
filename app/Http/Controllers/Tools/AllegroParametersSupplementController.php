<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\AllegroParametersSupplement;
use App\Services\MigrationExport\AllegroSchemaRefresh;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;

/** Owner-only package actions; new package work requires a completed, pinned schema refresh. */
final class AllegroParametersSupplementController extends Controller
{
    public function __invoke(Request $request, AllegroParametersSupplement $supplements, string $run)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['parent_attempt_id' => ['required', 'uuid'], 'fresh_plan_id' => ['sometimes', 'uuid'], 'action' => [$request->isMethod('post') ? 'required' : 'sometimes', 'in:start,tick'], 'download' => ['sometimes', 'string', 'max:40', 'regex:/\A(?:manifest|checksums|summary|archive-manifest|volume-[0-9]{6})\z/D']]);
        $owner = (int) $request->user()->id;
        try {
            $perform = function () use ($request, $supplements, $owner, $run, $input) {
                if ($request->isMethod('post')) {
                    ignore_user_abort(true);
                    $data = $input['action'] === 'start' ? $supplements->start($owner, $run, $input['parent_attempt_id'], requireFresh: true) : $supplements->tick($owner, $run, $input['parent_attempt_id']);
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
            };
            if ($request->isMethod('post') || isset($input['fresh_plan_id'])) {
                return app(AllegroSchemaRefresh::class)->withCompletedPlan($owner, $run, $input['parent_attempt_id'], $input['fresh_plan_id'] ?? null, $perform);
            }

            return $perform();
        } catch (MigrationExportException $exception) {
            return response()->json(['ok' => false, 'reason_code' => $exception->reasonCode], $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            $class = class_basename($exception);

            return response()->json(['ok' => false, 'reason_code' => 'supplement_server_exception', 'backend_exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) ? $class : 'AnonymousThrowable'], 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
