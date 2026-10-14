<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Images\JarekImageRunner;
use Illuminate\Http\Request;

class JarekFrImagesController extends Controller
{
    public function __invoke(Request $request, JarekImageRunner $runner)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $actor = (int) $request->user()->id;
        if ($request->isMethod('post')) {
            $input = $request->validate(['action' => ['required', 'in:preview,start,tick,pause,resume,stop'],
                'run_id' => ['required_unless:action,preview', 'uuid'], 'confirm' => ['required_if:action,start', 'in:'.JarekImageRunner::CONFIRM]]);
            $run = match ($input['action']) {
                'preview' => $runner->preview($actor),
                'start' => $runner->start($input['run_id'], $actor, $input['confirm']),
                'tick' => $runner->tick($input['run_id'], $actor),
                default => $runner->control($input['run_id'], $actor, $input['action']),
            };

            return response()->json(['schema_ready' => $runner->ready(), 'run' => $run])->header('Cache-Control', 'private, no-store');
        }
        if ($request->expectsJson()) {
            return response()->json(['schema_ready' => $runner->ready(), 'run' => $runner->latest($actor)])
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.jarek-gearboxes.fr-images')->header('Cache-Control', 'private, no-store');
    }
}
