<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Storefront\JarekFrPublication;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;

class JarekFrPublicationController extends Controller
{
    public function __invoke(Request $request, JarekFrPublication $publisher)
    {
        abort_if(StorefrontContext::isFrench($request), 403);
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $report = null;
        $result = null;
        if ($request->isMethod('post')) {
            $input = $request->validate(['action' => ['required', 'in:dry-run,apply'],
                'report_id' => ['required_if:action,apply', 'uuid'], 'confirm' => ['required_if:action,apply', 'string']]);
            if ($input['action'] === 'dry-run') {
                $report = $publisher->dryRun($request->user()->id);
            } else {
                $result = $publisher->apply($input['report_id'], $request->user()->id, $input['confirm']);
                $report = $publisher->report($input['report_id'], $request->user()->id);
            }
        } elseif ($request->filled('report_id')) {
            $request->validate(['report_id' => ['uuid']]);
            $report = $publisher->report($request->input('report_id'), $request->user()->id);
        }
        if ($request->expectsJson()) {
            return response()->json(['report' => $report, 'result' => $result])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.jarek-gearboxes.fr-publication', compact('report', 'result'))->header('Cache-Control', 'private, no-store');
    }
}
