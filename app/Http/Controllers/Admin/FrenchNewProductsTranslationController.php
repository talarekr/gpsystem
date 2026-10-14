<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\FrenchNewProductsTranslationRunner;
use App\Services\Storefront\FrenchProductTranslationRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FrenchNewProductsTranslationController extends Controller
{
    public function register(Request $request, FrenchProductTranslationRequests $requests)
    {
        $input = $request->validate(['part_id' => ['required', 'integer', 'exists:parts,id']]);
        if (! $requests->ready()) {
            throw ValidationException::withMessages(['schema' => 'Wymagana migracja zgłoszeń produktów.']);
        }
        $requests->register(Part::findOrFail($input['part_id']));

        return response()->json(['ok' => true, 'part_id' => (int) $input['part_id']]);
    }

    public function status(FrenchNewProductsTranslationRunner $runner)
    {
        return response()->json($runner->overview());
    }

    public function dryRun(Request $request, FrenchNewProductsTranslationRunner $runner)
    {
        $input = $request->validate(['include_failed' => ['sometimes', 'boolean']]);

        return response()->json(['report' => $runner->dryRun($request->user()->id, (bool) ($input['include_failed'] ?? false))]);
    }

    public function start(Request $request, FrenchNewProductsTranslationRunner $runner)
    {
        $input = $request->validate(['dry_run_id' => ['required', 'uuid'], 'confirm' => ['required', 'string', 'max:64']]);

        return response()->json(['status' => $runner->start($request->user()->id, $input['dry_run_id'], $input['confirm'])]);
    }

    public function tick(Request $request, FrenchNewProductsTranslationRunner $runner)
    {
        $input = $request->validate(['run_id' => ['required', 'uuid']]);

        return response()->json(['status' => $runner->tick($input['run_id'])]);
    }

    public function control(Request $request, FrenchNewProductsTranslationRunner $runner)
    {
        $input = $request->validate(['run_id' => ['required', 'uuid'], 'action' => ['required', 'in:pause,resume,stop']]);

        return response()->json(['status' => $runner->control($input['run_id'], $input['action'])]);
    }
}
