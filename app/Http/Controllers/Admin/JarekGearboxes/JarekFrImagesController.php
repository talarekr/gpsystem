<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Images\JarekImagePresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekFrImagesController extends Controller
{
    public function __invoke(Request $request, JarekImagePresentation $images)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        abort_unless(Schema::hasTable('jarek_fr_products'), 422, 'Brak tabeli ekspozycji Jarka.');
        if ($request->isMethod('post')) {
            $input = $request->validate(['action' => ['required', 'in:preview,prepare'],
                'after_id' => ['nullable', 'integer', 'min:0'], 'after_image' => ['nullable', 'integer', 'min:0', 'max:60'],
                'confirm' => ['required_if:action,prepare', 'in:PREPARE-JAREK-FR-LOCAL-IMAGES']]);

            return response()->json($images->batch((int) ($input['after_id'] ?? 0), (int) ($input['after_image'] ?? 0), $input['action'] === 'prepare'))
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.jarek-gearboxes.fr-images')->header('Cache-Control', 'private, no-store');
    }
}
