<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentLabelService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
            ->get(['id', 'order_number', 'customer_name']);
    }

    public function getPerPageOptionsProperty(): array
    {
        return ['25' => '25', '50' => '50', '100' => '100'];
    }

    protected function normalizedPerPage(): int
    {
        return (int) (array_key_exists($this->perPage, $this->perPageOptions) ? $this->perPage : '25');
    }
}
