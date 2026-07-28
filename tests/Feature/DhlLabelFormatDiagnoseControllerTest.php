<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DhlLabelFormatDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostic_is_admin_authenticated_and_reports_pdf_dimensions_without_marketplace_write(): void
    {
        Storage::fake('local');
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Page /MediaBox [0 0 288 432] >> endobj\n%%EOF";
        Storage::disk('local')->put('shipments/labels/dhl/123.pdf', $pdf);
        $shipment = Shipment::query()->create([
            'carrier' => 'dhl',
            'tracking_number' => '123',
            'carrier_shipment_id' => '123',
            'label_path' => 'shipments/labels/dhl/123.pdf',
            'label_format' => 'application/pdf',
            'request_payload' => ['shipment' => ['shipmentInfo' => ['labelType' => 'LBLP']]],
        ]);

        $this->getJson('/admin/tools/dhl/label-format-diagnose?shipment_id='.$shipment->id)->assertUnauthorized();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->getJson('/admin/tools/dhl/label-format-diagnose?shipment_id='.$shipment->id)
            ->assertOk()
            ->assertJsonPath('shipment_id', $shipment->id)
            ->assertJsonPath('tracking', '123')
            ->assertJsonPath('current_label_format', 'LBLP')
            ->assertJsonPath('requested_label_format', 'BLP')
            ->assertJsonPath('dhl_api_supports_blp_pdf', true)
            ->assertJsonPath('pdf_page_size.width_mm', 101.6)
            ->assertJsonPath('pdf_page_size.height_mm', 152.4)
            ->assertJsonPath('mime_type', 'application/pdf')
            ->assertJsonPath('file_size_bytes', strlen($pdf))
            ->assertJsonPath('stored_at', 'shipments/labels/dhl/123.pdf')
            ->assertJsonPath('marketplace_write', false);
    }
}
