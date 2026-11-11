<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\AllegroOfferParameterReadback;
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
        $input = $request->validate(['parent_attempt_id' => ['required', 'uuid'], 'fresh_plan_id' => ['sometimes', 'uuid'], 'readback_plan_id' => ['sometimes', 'uuid'], 'plan_id' => ['sometimes', 'uuid'], 'plan_digest' => ['sometimes', 'string', 'size:64'], 'confirm' => ['sometimes', 'boolean'], 'action' => [$request->isMethod('post') ? 'required' : 'sometimes', 'in:plan,start,tick'], 'download' => ['sometimes', 'string', 'max:40', 'regex:/\A(?:manifest|checksums|summary|archive-manifest|parameters-csv|volume-[0-9]{6})\z/D']]);
        $owner = (int) $request->user()->id;
        $diagnostics = null;
        try {
            $diagnostics = $supplements->diagnostics($owner, $run, $input['parent_attempt_id'], $input['action'] ?? (isset($input['download']) ? 'download' : 'status'), $input['fresh_plan_id'] ?? null, $input['readback_plan_id'] ?? null);
            $perform = function () use ($request, $supplements, $owner, $run, $input, $diagnostics) {
                if ($request->isMethod('post')) {
                    ignore_user_abort(true);
                    if ($input['action'] === 'plan') {
                        $data = $supplements->plan($owner, $run, $input['parent_attempt_id']);
                    } elseif ($input['action'] === 'start') {
                        $data = $supplements->confirmPlan($owner, $run, $input['parent_attempt_id'], $input['plan_id'] ?? '', $input['plan_digest'] ?? '', $input['confirm'] ?? false);
                    } else {
                        $data = $supplements->tick($owner, $run, $input['parent_attempt_id']);
                    }
                } elseif (isset($input['download'])) {
                    $path = $supplements->download($owner, $run, $input['parent_attempt_id'], $input['download']);
                    $response = response()->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
                    $response->setPrivate();
                    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

                    return $response;
                } else {
                    $data = $supplements->status($owner, $run, $input['parent_attempt_id']);
                }

                $data += ($request->isMethod('post') ? ['last_operation_failed' => false] : []) + $diagnostics->stored();
                $response = response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
                if ($request->isMethod('post')) {
                    $diagnostics->success();
                }

                return $response;
            };
            if ($request->isMethod('post') || isset($input['fresh_plan_id']) || isset($input['readback_plan_id'])) {
                return app(AllegroSchemaRefresh::class)->withCompletedPlan($owner, $run, $input['parent_attempt_id'], $input['fresh_plan_id'] ?? null, function () use ($request, $owner, $run, $input, $perform) {
                    if ($request->isMethod('post') || isset($input['readback_plan_id'])) {
                        return app(AllegroOfferParameterReadback::class)->withCompletedPlan($owner, $run, $input['parent_attempt_id'], $input['readback_plan_id'] ?? null, $perform);
                    }

                    return $perform();
                });
            }

            return $perform();
        } catch (MigrationExportException $exception) {
            $failure = $request->isMethod('post') ? $diagnostics?->failure($exception) : null;
            $saved = $diagnostics?->stored() ?? [];

            return response()->json(['ok' => false, 'reason_code' => $exception->reasonCode] + ($failure ?? []) + $saved, $exception->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $exception) {
            $failure = $diagnostics?->failure($exception);

            return response()->json(['ok' => false] + ($failure ?? ['reason_code' => 'supplement_server_exception']), 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
