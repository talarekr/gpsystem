<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use App\Services\MigrationExport\OperatorFinalizationDiagnostics;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GpswissMigrationExportController extends Controller
{
    public function __invoke(Request $request, GpswissMigrationExport $exports, ?string $run = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $owner = (int) $request->user()->id;
        $input = $request->validate(['action' => ['sometimes', 'in:start,tick,resume,snapshot_tick,pause_snapshot,retry_snapshot,abort_snapshot,retry_photos,accept_parts_drift,use_external_photos'], 'download' => ['sometimes', 'in:sample,full,artifact'], 'format' => ['sometimes', 'in:status,compact,artifacts,dataset-conflict'],
            'artifact' => ['sometimes', 'string', 'max:180', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D'],
            'confirm_external_photos' => ['sometimes', 'boolean'], 'confirm_parts_drift' => ['sometimes', 'string', 'max:80'], 'confirm_restart' => ['sometimes', 'boolean'], 'confirm_abort' => ['sometimes', 'boolean'], 'confirm_retry_photos' => ['sometimes', 'boolean'], 'expected_snapshot_attempt' => ['present_if:action,snapshot_tick', 'nullable', 'uuid']]);
        $statusStarted = microtime(true);
        $serializingStatus = false;
        $compactRequest = ($input['format'] ?? null) === 'compact' && $request->isMethod('get');
        $finished = false;
        $reserve = null;
        $operatorRequestId = null;
        $operatorResponse = false;
        if ((($input['action'] ?? null) === 'snapshot_tick' || $compactRequest) && $run) {
            $reserve = str_repeat('r', 128 * 1024);
            register_shutdown_function(static function () use (&$finished, &$reserve, $exports, $owner, $run, $compactRequest): void {
                $reserve = null;
                $fatal = error_get_last();
                if (! $finished && $fatal && in_array($fatal['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    try {
                        if ($compactRequest) {
                            $reason = str_contains($fatal['message'], 'Maximum execution time') ? 'backend_timeout' : (str_contains($fatal['message'], 'Allowed memory size') ? 'memory_exhaustion' : 'php_fatal_error');
                            $exports->markStatusFatal($owner, $run, $reason);
                        } else {
                            $exports->markTickFatal($owner, $run, str_contains($fatal['message'], 'Maximum execution time') ? 'backend_timeout' : (str_contains($fatal['message'], 'Allowed memory size') ? 'memory_exhaustion' : 'php_fatal_error'));
                        }
                    } catch (\Throwable) {
                    }
                }
            });
        }
        try {
            if ($request->isMethod('post')) {
                abort_unless(isset($input['action']), 422);
                if ($run && $input['action'] === 'snapshot_tick') {
                    $operatorRequestId = $exports->beginOperatorFinalizeRequest($owner, $run);
                }
                ignore_user_abort(true); // Finish this authorized checkpoint even if its response is lost.
                if ($input['action'] === 'start') {
                    $data = $exports->prepareBrowser($owner, $run);
                } else {
                    if (! $run) {
                        return $this->error('invalid_run_id', 422);
                    }
                    $expectedAttempt = $input['expected_snapshot_attempt'] ?? null;
                    $data = match ($input['action']) {
                        'retry_snapshot' => $request->boolean('confirm_restart')
                            ? $exports->retryBrowserSnapshot($owner, $run, $expectedAttempt)
                            : throw new MigrationExportException('snapshot_restart_confirmation_required', 422),
                        'abort_snapshot' => $request->boolean('confirm_abort')
                            ? $exports->abortSnapshot($owner, $run, $expectedAttempt)
                            : throw new MigrationExportException('snapshot_abort_confirmation_required', 422),
                        'resume' => $exports->resume($owner, $run, $expectedAttempt),
                        'accept_parts_drift' => $exports->acceptPartsDrift($owner, $run, $expectedAttempt, $input['confirm_parts_drift'] ?? ''),
                        'snapshot_tick' => $exports->browserSnapshotTick($owner, $run, $expectedAttempt),
                        'pause_snapshot' => $exports->pauseBrowserSnapshot($owner, $run),
                        'use_external_photos' => $request->boolean('confirm_external_photos')
                            ? $exports->useExternalPhotos($owner, $run, $expectedAttempt)
                            : throw new MigrationExportException('external_photo_mode_confirmation_required', 422),
                        'retry_photos' => $request->boolean('confirm_retry_photos')
                            ? $exports->retryBrowserPhotos($owner, $run)
                            : throw new MigrationExportException('photo_retry_confirmation_required', 422),
                        default => $exports->tick($owner, $run),
                    };
                }

                if ($input['action'] === 'snapshot_tick') {
                    $data['tick_diagnostic'] = $data['last_tick'] ?? null;
                    unset($data['snapshot_progress']['datasets']);
                }

                if ($operatorRequestId && ($data['last_error'] ?? null)) {
                    $reason = OperatorFinalizationDiagnostics::controlledReason($data['last_error']);
                    $class = $data['last_tick']['backend_exception_class'] ?? null;
                    $diagnostic = $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, $reason ?? 'server_exception', $class, $reason ? 'controlled_blocker' : 'infrastructure');

                    return $this->error($reason ?? 'server_exception', $reason ? 409 : 500, ['backend_exception_class' => $class, 'operator_finalize_diagnostic' => $diagnostic]);
                }
                $operatorResponse = $operatorRequestId !== null;
                $response = response()->json($data)->header('Cache-Control', 'private, no-store');
                if ($operatorRequestId) {
                    $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, 'response_serialized');
                }

                return $response;
            }
            if ($run && isset($input['download'])) {
                if ($input['download'] === 'artifact' && ! isset($input['artifact'])) {
                    return $this->error('invalid_artifact_key', 422);
                }
                $path = $exports->download($owner, $run, $input['download'], $input['artifact'] ?? null);
                $name = $input['download'] === 'artifact' ? basename($path) : 'gpswiss-wystawczesc-'.$input['download'].'-'.$run.'.zip';

                $response = response()->download($path, $name, ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
                // BinaryFileResponse defaults to public during construction; override afterwards.
                $response->setPrivate();
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

                return $response;
            }
            if (($input['format'] ?? null) === 'compact') {
                $data = $run ? $exports->compactStatus($owner, $run) : $exports->discover($owner, true);

                $serializingStatus = true;

                return $this->compactReply($data, $statusStarted);
            }
            if ($run && ($input['format'] ?? null) === 'dataset-conflict') {
                return response()->json($exports->datasetConflict($owner, $run))->header('Cache-Control', 'private, no-store');
            }
            if ($run && ($input['format'] ?? null) === 'artifacts') {
                return response()->json($exports->artifacts($owner, $run))->header('Cache-Control', 'private, no-store');
            }
            if (! $run && ($input['format'] ?? null) === 'status') {
                return response()->json($exports->discover($owner))->header('Cache-Control', 'private, no-store');
            }

            return ($run ? response()->json($exports->status($owner, $run)) : response()->view('admin.tools.gpswiss-migration-export'))
                ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
        } catch (MigrationExportException $e) {
            $diagnostic = $operatorRequestId ? $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, $e->reasonCode, $e->details['backend_exception_class'] ?? get_class($e), 'controlled_blocker') : null;

            return $this->error($e->reasonCode, $e->httpStatus, $e->details + ($diagnostic ? ['operator_finalize_diagnostic' => $diagnostic] : []));
        } catch (HttpExceptionInterface $e) {
            // Route/controller guards have no filesystem or database exception body to disclose.
            return $this->error($e->getStatusCode() === 404 ? 'run_not_found' : 'other', $e->getStatusCode());
        } catch (\RuntimeException $e) {
            $safe = in_array($e->getMessage(), ['snapshot_failed', 'zip_extension_required', 'export_root_must_be_private', 'export_root_permissions_must_be_private'], true) ? $e->getMessage() : 'other';
            $class = str_contains(get_class($e), '@anonymous') ? 'AnonymousThrowable' : get_class($e);
            $diagnostic = $operatorRequestId ? $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, $safe === 'other' ? 'server_exception' : $safe, $class, $operatorResponse ? 'response_serialization' : 'infrastructure') : null;

            return $this->error($safe === 'other' ? 'server_exception' : $safe, $safe === 'other' ? 500 : 409, ['backend_exception_class' => $class] + ($diagnostic ? ['operator_finalize_diagnostic' => $diagnostic] : []));
        } catch (\Throwable $e) {
            // Do not return exception bodies, paths, SQL, or record data in diagnostics.
            $class = str_contains(get_class($e), '@anonymous') ? 'AnonymousThrowable' : get_class($e);
            $diagnostic = $operatorRequestId ? $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, 'server_exception', $class, $operatorResponse ? 'response_serialization' : 'infrastructure') : null;

            return $this->error($serializingStatus ? 'status_response_serialization_exception' : 'server_exception', 500, ['backend_exception_class' => $class] + ($diagnostic ? ['operator_finalize_diagnostic' => $diagnostic] : []));
        } finally {
            $finished = true;
            $reserve = null;
        }
    }

    public function kick(Request $request, GpswissMigrationExport $exports, string $run)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['attempt_id' => ['required', 'uuid'],
            'cursor' => ['prohibited'], 'dataset' => ['prohibited'], 'action' => ['prohibited']]);
        $owner = (int) $request->user()->id;
        $started = microtime(true);
        $finished = false;
        $operatorRequestId = null;
        $reserve = str_repeat('r', 128 * 1024);
        register_shutdown_function(static function () use (&$finished, &$reserve, $exports, $owner, $run): void {
            $reserve = null;
            $fatal = error_get_last();
            if (! $finished && $fatal && in_array($fatal['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                try {
                    $exports->markTickFatal($owner, $run, str_contains($fatal['message'], 'Maximum execution time') ? 'backend_timeout' : (str_contains($fatal['message'], 'Allowed memory size') ? 'memory_exhaustion' : 'php_fatal_error'));
                } catch (\Throwable) {
                }
            }
        });
        try {
            ignore_user_abort(true);
            $operatorRequestId = $exports->beginOperatorFinalizeRequest($owner, $run, 'kick');
            $data = $exports->kickBrowserSnapshot($owner, $run, $input['attempt_id']);
            $data['tick_diagnostic'] = $data['last_tick'] ?? null;
            $data['kick_diagnostic'] = ['action' => 'kick', 'reason_code' => $data['kick_reason'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 8192) {
                throw new MigrationExportException('compact_status_budget_exceeded', 500);
            }

            if ($operatorRequestId && ($data['last_error'] ?? null)) {
                $reason = OperatorFinalizationDiagnostics::controlledReason($data['last_error']);
                $class = $data['last_tick']['backend_exception_class'] ?? null;
                $diagnostic = $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, $reason ?? 'server_exception', $class, $reason ? 'controlled_blocker' : 'infrastructure');

                return $this->error($reason ?? 'server_exception', $reason ? 409 : 500, ['backend_exception_class' => $class, 'operator_finalize_diagnostic' => $diagnostic]);
            }
            $response = response()->json($data)->header('Cache-Control', 'private, no-store');
            if ($operatorRequestId) {
                $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, 'response_serialized');
            }

            return $response;
        } catch (MigrationExportException $error) {
            $diagnostic = $operatorRequestId ? $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, $error->reasonCode, $error->details['backend_exception_class'] ?? get_class($error), 'controlled_blocker') : null;

            return $this->error($error->reasonCode, $error->httpStatus, $error->details + ($diagnostic ? ['operator_finalize_diagnostic' => $diagnostic] : []));
        } catch (\Throwable $error) {
            $class = str_contains(get_class($error), '@anonymous') ? 'AnonymousThrowable' : get_class($error);
            $diagnostic = $operatorRequestId ? $exports->finishOperatorFinalizeRequest($owner, $run, $operatorRequestId, 'server_exception', $class, 'infrastructure') : null;

            return $this->error('server_exception', 500, ['backend_exception_class' => $class] + ($diagnostic ? ['operator_finalize_diagnostic' => $diagnostic] : []));
        } finally {
            $finished = true;
            $reserve = null;
        }
    }

    private function compactReply(array $data, float $started)
    {
        $data['status_request_diagnostic'] = ['action' => 'compact_status', 'route_name' => 'admin.tools.gpswiss-migration-export',
            'reason_code' => 'status_loaded', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
        $data['status_response_bytes'] = 0;
        for ($i = 0; $i < 4; $i++) {
            $bytes = strlen(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($bytes === $data['status_response_bytes']) {
                break;
            }
            $data['status_response_bytes'] = $bytes;
        }
        if ($bytes > 8192) {
            throw new MigrationExportException('compact_status_budget_exceeded', 500);
        }

        return response()->json($data, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Authenticated fallback: UUID validation and unmatched exporter paths must be distinguishable. */
    public function invalidRoute(Request $request, string $invalidPath)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);

        return $this->error(str_contains($invalidPath, '/') ? 'route_not_found' : 'invalid_run_id', 404);
    }

    private function error(string $reason, int $http, array $details = [])
    {
        return response()->json(['ok' => false, 'reason_code' => $reason, 'http_status' => $http,
            'database_writes' => false, 'source_file_writes' => false, 'provider_calls' => false] + $details, $http)
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
    }
}
