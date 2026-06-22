<?php

namespace App\Filament\Resources\MarketplaceCategoryResource\Pages;

use App\Filament\Resources\MarketplaceCategoryResource;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMarketplaceCategory extends EditRecord
{
    protected static string $resource = MarketplaceCategoryResource::class;

    private const CHANNELS = ['ovoko', 'allegro_main', 'ebay', 'ebay_de', 'ebay_fr'];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PartCategory $record */
        $record = $this->record;
        $mappings = $record->marketplaceMappings()->get()->keyBy('channel');

        foreach (self::CHANNELS as $channel) {
            $mapping = $mappings->get($channel);
            $data['mappings'][$channel] = [
                'external_category_id' => $mapping?->external_category_id,
                'external_category_name' => $mapping?->external_category_name,
                'external_category_path' => $mapping?->external_category_path,
                'is_blocked' => (bool) ($mapping?->is_blocked ?? false),
                'block_reason' => $mapping?->block_reason,
                'shipping_group' => $mapping?->shipping_group,
                'fulfillment_policy_id' => $mapping?->fulfillment_policy_id,
                'notes' => $mapping?->notes,
            ];
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof PartCategory, 404);

        foreach (self::CHANNELS as $channel) {
            $payload = $data['mappings'][$channel] ?? [];

            MarketplaceCategoryMapping::query()->updateOrCreate(
                ['local_category_id' => $record->id, 'channel' => $channel],
                [
                    'local_category_name' => $record->name,
                    'local_category_path' => $record->category_path ?: $record->full_slug_path,
                    'old_category_id' => $record->external_id,
                    'external_category_id' => $this->nullableString($payload['external_category_id'] ?? null),
                    'external_category_name' => $this->nullableString($payload['external_category_name'] ?? null),
                    'external_category_path' => $this->nullableString($payload['external_category_path'] ?? null),
                    'is_blocked' => (bool) ($payload['is_blocked'] ?? false),
                    'block_reason' => $this->nullableString($payload['block_reason'] ?? null),
                    'shipping_group' => $this->nullableString($payload['shipping_group'] ?? null),
                    'fulfillment_policy_id' => $this->nullableString($payload['fulfillment_policy_id'] ?? null),
                    'notes' => $this->nullableString($payload['notes'] ?? null),
                ],
            );
        }

        return $record;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Zapisano mappingi kategorii marketplace');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
