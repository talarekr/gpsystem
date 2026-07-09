<?php

namespace App\Filament\Pages;

use App\Services\Shipments\DhlShipmentService;

class CreateShipment extends Shipments
{
    protected static ?string $navigationGroup = 'Przesyłki';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Dodaj';
    protected static ?string $title = 'Dodaj przesyłkę';
    protected static ?int $navigationSort = 69;
    protected static ?string $slug = 'shipments/create';
    protected static string $view = 'filament.pages.create-shipment';

    public function mount(DhlShipmentService $dhl): void
    {
        parent::mount();

        $this->dhlForm = $dhl->defaults();
        $this->showDhlForm = true;
    }
}
