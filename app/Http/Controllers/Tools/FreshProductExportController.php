<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\MigrationExport\FreshProductExport;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class FreshProductExportController extends Controller
{
    public function __invoke(Request $request, FreshProductExport $exports, ?string $export = null)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate(['action' => ['sometimes', 'in:prepare,capture,tick,resume,stop'], 'type' => ['required_if:action,prepare', 'in:czesci,sprzedane,do_wystawienia'], 'mode' => ['required_if:action,prepare', 'in:sample,full'], 'download' => ['sometimes', 'in:czesci.csv,sprzedane.csv,do_wystawienia.csv,manifest.json']]);
        $owner = (int) $request->user()->id;
        try {
            if ($request->isMethod('post')) {
                ignore_user_abort(true);
                if (($input['action'] ?? null) === 'prepare') {
                    abort_if($export !== null, 422);
                    $data = $exports->prepare($owner, $input['type'], $input['mode']);
                } else {
                    abort_unless($export && isset($input['action']), 422);
                    $data = $exports->action($owner, $export, $input['action']);
                }
            } elseif ($export && isset($input['download'])) {
                return response()->download($exports->download($owner, $export, $input['download']), $input['download'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive']);
            } elseif ($export) {
                $data = $exports->status($owner, $export);
            } else {
                return response()->view('admin.tools.product-migration-export')->header('Cache-Control', 'private, no-store');
            }

            $data['diagnostic'] = $exports->failureReceipt() ?? ($data['diagnostic'] ?? null);

            return response()->json($data)->header('Cache-Control', 'private, no-store');
        } catch (MigrationExportException $e) {
            return response()->json(['error' => $e->reasonCode, 'export_id' => $exports->failureReceipt()['export_id'] ?? $export, 'diagnostic' => $exports->failureReceipt()], $e->httpStatus)->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['error' => 'product_export_operation_failed', 'export_id' => $exports->failureReceipt()['export_id'] ?? $export, 'diagnostic' => $exports->failureReceipt()], 500)->header('Cache-Control', 'private, no-store');
        }
    }
}
