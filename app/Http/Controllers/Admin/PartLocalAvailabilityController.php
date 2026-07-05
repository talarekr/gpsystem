<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Admin\PartLocalAvailabilityUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartLocalAvailabilityController extends Controller
{
    public function update(Request $request, Part $part, PartLocalAvailabilityUpdater $updater): JsonResponse
    {
        $validated = $request->validate([
            'availability_flag' => ['required', Rule::in(['0', '1', 0, 1])],
        ]);

        return response()->json($updater->update($part, $validated['availability_flag']));
    }
}
