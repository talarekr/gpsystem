<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\AllegroSchemaRefresh;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;

/** Owner-only schema plan/status/ticks. Provider GET begins only after explicit confirmation. */
final class AllegroSchemaRefreshController extends Controller
{
    public function __invoke(Request $request, AllegroSchemaRefresh $refresh, string $run)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['parent_attempt_id' => ['required', 'uuid'], 'action' => ['sometimes', 'in:plan,new_plan,start,tick'], 'plan_digest' => ['sometimes', 'string', 'size:64'], 'confirm' => ['sometimes', 'boolean']]);
        $owner = (int) $request->user()->id;
        try {
            if ($request->isMethod('post')) {
                ignore_user_abort(true);
                $data = match ($input['action'] ?? null) {
                    'plan' => $refresh->plan($owner, $run, $input['parent_attempt_id']),
                    'new_plan' => $refresh->plan($owner, $run, $input['parent_attempt_id'], newPlan: true),
                    'start' => $refresh->start($owner, $run, $input['parent_attempt_id'], $input['plan_digest'] ?? '', $input['confirm'] ?? false),
                    'tick' => $refresh->tick($owner, $run, $input['parent_attempt_id']),
                    default => throw new MigrationExportException('schema_action_required', 422),
                };
            } else {
                $data = $refresh->status($owner, $run, $input['parent_attempt_id']);
            }

            return response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
        } catch (MigrationExportException $exception) {
            return response()->json(['ok' => false, 'reason_code' => $exception->reasonCode], $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            $class = class_basename($exception);

            return response()->json(['ok' => false, 'reason_code' => 'schema_refresh_server_exception', 'backend_exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) ? $class : 'AnonymousThrowable'], 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
