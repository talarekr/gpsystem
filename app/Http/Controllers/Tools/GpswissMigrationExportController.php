<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GpswissMigrationExportController extends Controller
{
    public function __invoke(Request $request, GpswissMigrationExport $exports, ?string $run = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $owner = (int) $request->user()->id;
        $input = $request->validate(['action' => ['sometimes', 'in:start,tick,resume,snapshot_tick,pause_snapshot,retry_snapshot,abort_snapshot,retry_photos'], 'download' => ['sometimes', 'in:sample,full,artifact'], 'format' => ['sometimes', 'in:status'],
            'artifact' => ['sometimes', 'string', 'max:180', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D'],
            'confirm_restart' => ['sometimes', 'boolean'], 'confirm_abort' => ['sometimes', 'boolean'], 'confirm_retry_photos' => ['sometimes', 'boolean'], 'expected_snapshot_attempt' => ['present_if:action,snapshot_tick', 'nullable', 'uuid']]);
        try {
            if ($request->isMethod('post')) {
                abort_unless(isset($input['action']), 422);
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
                        'resume' => $exports->resume($owner, $run),
                        'snapshot_tick' => $exports->browserSnapshotTick($owner, $run, $expectedAttempt),
                        'pause_snapshot' => $exports->pauseBrowserSnapshot($owner, $run),
                        'retry_photos' => $request->boolean('confirm_retry_photos')
                            ? $exports->retryBrowserPhotos($owner, $run)
                            : throw new MigrationExportException('photo_retry_confirmation_required', 422),
                        default => $exports->tick($owner, $run),
                    };
                }

                return response()->json($data)->header('Cache-Control', 'private, no-store');
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
            if (! $run && ($input['format'] ?? null) === 'status') {
                return response()->json($exports->discover($owner))->header('Cache-Control', 'private, no-store');
            }

            return ($run ? response()->json($exports->status($owner, $run)) : response()->view('admin.tools.gpswiss-migration-export'))
                ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, noarchive');
        } catch (MigrationExportException $e) {
            return $this->error($e->reasonCode, $e->httpStatus, $e->details);
        } catch (HttpExceptionInterface $e) {
            // Route/controller guards have no filesystem or database exception body to disclose.
            return $this->error($e->getStatusCode() === 404 ? 'run_not_found' : 'other', $e->getStatusCode());
        } catch (\RuntimeException $e) {
            $safe = in_array($e->getMessage(), ['snapshot_failed', 'zip_extension_required', 'export_root_must_be_private', 'export_root_permissions_must_be_private'], true) ? $e->getMessage() : 'other';

            return $this->error($safe, 409);
        } catch (\Throwable) {
            // Do not return exception bodies, paths, SQL, or record data in diagnostics.
            return $this->error('other', 500);
        }
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
