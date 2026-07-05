<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Services\Admin\LocalOrderStatusUpdater;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListOrders extends Page
{
    use WithPagination;

    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.resources.orders.pages.list-orders';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'marketplace')]
    public ?string $marketplace = null;

    #[Url(as: 'status')]
    public ?string $status = null;

    #[Url(as: 'test')]
    public ?string $testImport = null;

    #[Url(as: 'batch')]
    public ?string $sourceBatch = null;

    #[Url(as: 'sort')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page')]
    public int $perPage = 10;

    public array $perPageOptions = [10 => '10', 30 => '30', 50 => '50', 100 => '100'];

    public function mount(): void
    {
        $requestedPerPage = $this->requestedPerPage();

        if ($requestedPerPage !== null) {
            $this->perPage = $requestedPerPage;

            return;
        }

        if ($this->status === 'new') {
            $this->perPage = 30;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'marketplace', 'status', 'testImport', 'sourceBatch', 'sortDirection', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->marketplace = null;
        $this->status = null;
        $this->testImport = null;
        $this->sourceBatch = null;
        $this->resetPage();
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->marketplace, $this->status, $this->testImport, $this->sourceBatch])
            ->filter(fn (?string $value): bool => filled($value))
            ->count();
    }

    public function getSourceBatchOptionsProperty(): array
    {
        return Order::query()
            ->whereNotNull('source_batch')
            ->distinct()
            ->orderBy('source_batch')
            ->pluck('source_batch', 'source_batch')
            ->all();
    }


    public function updateOrderStatus(int $orderId, string $status, LocalOrderStatusUpdater $updater): void
    {
        $order = Order::query()->findOrFail($orderId);

        try {
            $updater->update($order, $status);

            Notification::make()
                ->title('Status zamówienia został zapisany lokalnie.')
                ->success()
                ->send();
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Nie zapisano statusu')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getOrdersProperty(): LengthAwarePaginator
    {
        return $this->getOrdersQuery()->paginate($this->normalizedPerPage())->withQueryString();
    }

    protected function normalizedPerPage(): int
    {
        $perPage = (int) $this->perPage;

        if (! array_key_exists($perPage, $this->perPageOptions)) {
            return 10;
        }

        return $perPage;
    }

    protected function requestedPerPage(): ?int
    {
        foreach (['per_page', 'perPage', 'limit', 'pageSize', 'page_size'] as $parameter) {
            $value = Request::query($parameter);

            if ($value !== null && array_key_exists((int) $value, $this->perPageOptions)) {
                return (int) $value;
            }
        }

        return null;
    }

    protected function getOrdersQuery(): Builder
    {
        $sortDirection = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';

        return Order::query()
            ->with(['items.part.images', 'items.part.storageLocation', 'items.marketplaceListing.part.images', 'items.marketplaceListing.part.storageLocation', 'shipments'])
            ->when(filled($this->search), function (Builder $query): void {
                $search = trim($this->search);

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('order_number', 'like', "%{$search}%")
                        ->orWhere('marketplace_order_id', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(filled($this->marketplace), fn (Builder $query): Builder => $query->where('marketplace', $this->marketplace))
            ->when(filled($this->status), fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when(filled($this->testImport), fn (Builder $query): Builder => $query->where('test_import', $this->testImport === '1'))
            ->when(filled($this->sourceBatch), fn (Builder $query): Builder => $query->where('source_batch', $this->sourceBatch))
            ->orderBy('ordered_at', $sortDirection)
            ->orderByDesc('id');
    }
}
