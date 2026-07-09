<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\DhlShipmentService;
use App\Services\Shipments\ShipmentLabelService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Shipments extends Page
{
    use WithPagination;

    protected static ?string $navigationGroup = 'Przesyłki';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Przesyłki';
    protected static ?string $title = 'Przesyłki';
    protected static ?int $navigationSort = 70;
    protected static string $view = 'filament.pages.shipments';

    public ?array $preview = null;

    public ?array $dhlForm = null;

    public bool $showDhlForm = false;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'carrier')]
    public ?string $carrier = null;

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Url(as: 'per_page')]
    public string $perPage = '25';

    public function updating(string $property): void
    {
        if ($property !== 'page') $this->resetPage();
    }

    public function mount(DhlShipmentService $dhl): void
    {
        $this->dhlForm = $dhl->defaults();
    }

    public function openDhlForm(?int $orderId = null, ?int $shipmentId = null): void
    {
        $shipment = $shipmentId ? Shipment::query()->with('order')->find($shipmentId) : null;
        $order = $orderId ? Order::query()->find($orderId) : $shipment?->order;

        $this->dhlForm = app(DhlShipmentService::class)->defaults($order, $shipment);
        $this->showDhlForm = true;
        $this->preview = null;
    }

    public function createDhlShipment(DhlShipmentService $dhl): void
    {
        if ((bool) data_get($this->dhlForm, 'parcel.euro_return') && data_get($this->dhlForm, 'parcel.type') !== 'PALLET') {
            $this->addError('dhlForm.parcel.euro_return', 'Zwrot palety jest dostępny tylko dla typu paleta.');
            return;
        }

        $this->dhlForm = $dhl->normalizeForm($this->dhlForm ?? []);

        $this->validate($dhl->rules());

        try {
            $shipment = $dhl->create($this->dhlForm ?? []);
            $this->afterDhlShipmentCreated($shipment);
            $this->showDhlForm = false;
            $this->preview = ['ok' => true, 'shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number, 'label_path' => $shipment->label_path, 'pickup_ordered' => false];

            Notification::make()->title('Utworzono przesyłkę DHL')->body('Numer listu: '.$shipment->tracking_number)->success()->send();
        } catch (\Throwable $exception) {
            Notification::make()->title('DHL odrzucił utworzenie przesyłki')->body($exception->getMessage())->danger()->send();
        }
    }

    protected function afterDhlShipmentCreated(Shipment $shipment): void
    {
        // Standalone Przesyłki → Dodaj deliberately stops here and never calls eBay.
    }

    public function downloadLabel(int $shipmentId)
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        $path = $this->safeString($shipment->label_path);
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404, 'Label not found.');

        return Storage::disk('local')->download($path, 'dhl-'.($this->safeString($shipment->tracking_number) ?: $shipment->id).'.pdf');
    }

    public function generateLabel(string $carrier, ?int $shipmentId = null, bool $confirm = false): void
    {
        $shipment = $shipmentId ? Shipment::query()->with('order')->find($shipmentId) : null;
        $order = $shipment?->order ?: Order::query()->latest('id')->first();
        $service = app(ShipmentLabelService::class);
        $this->preview = $confirm ? $service->confirm($carrier, $order, $shipment) : $service->preview($carrier, $order, $shipment);

        Notification::make()
            ->title($confirm ? 'Próba wygenerowania etykiety zakończona' : 'Dry-run etykiety zakończony')
            ->body(($this->preview['validation']['ok'] ?? false) ? 'Dane kompletne.' : 'Brakuje danych: '.implode(', ', $this->preview['validation']['missing'] ?? []))
            ->send();
    }

    public function getShipmentsProperty(): LengthAwarePaginator
    {
        return Shipment::query()
            ->with('order:id,order_number,customer_name')
            ->when(filled($this->search), function (Builder $query): void {
                $search = trim($this->search);
                $query->where(fn (Builder $query) => $query
                    ->where('id', $search)
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', "%{$search}%")));
            })
            ->when(filled($this->carrier), fn (Builder $query) => $query->where('carrier', $this->carrier))
            ->when(filled($this->status), fn (Builder $query) => $query->where('shipment_status', $this->status))
            ->latest('id')
            ->paginate($this->normalizedPerPage())
            ->withQueryString();
    }

    public function getOrdersWithoutShipmentProperty()
    {
        return Order::query()
            ->whereDoesntHave('shipments')
            ->latest('id')
            ->limit(10)
            ->get(['id', 'order_number', 'customer_name', 'company_name', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country']);
    }

    public function getDhlCountryOptionsProperty(): array
    {
        return app(DhlShipmentService::class)->countryOptions();
    }

    public function getPerPageOptionsProperty(): array
    {
        return ['25' => '25', '50' => '50', '100' => '100'];
    }

    public function safeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function labelExists(Shipment $shipment): bool
    {
        $path = $this->safeString($shipment->label_path);

        if ($path === null || str_contains($path, "\0") || preg_match('/^[a-z]+:\/\//i', $path) === 1) {
            return false;
        }

        try {
            return Storage::disk('local')->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function normalizedPerPage(): int
    {
        return (int) (array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25');
    }
}
