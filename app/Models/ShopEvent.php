<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ShopEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'event_type',
        'title',
        'description',
        'occurred_at',
        'is_read',
        'requires_action',
        'severity',
        'customer_name',
        'external_reference',
        'url',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_read' => 'boolean',
            'requires_action' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function sourceLabel(): string
    {
        return [
            'storefront' => 'Sklep',
            'ovoko' => 'Ovoko',
            'allegro' => 'Allegro',
            'ebay' => 'eBay',
            'manual' => 'Ręcznie',
        ][$this->source] ?? 'Inne';
    }

    public function typeLabel(): string
    {
        return [
            'order' => 'Zamówienie',
            'return' => 'Zwrot',
            'complaint' => 'Reklamacja',
            'customer_message' => 'Wiadomość',
            'product_question' => 'Pytanie o produkt',
            'shipment' => 'Wysyłka',
            'payment' => 'Płatność',
            'task' => 'Zadanie',
        ][$this->event_type] ?? 'Zdarzenie';
    }

    public function severityLabel(): string
    {
        return [
            'info' => 'Informacja',
            'success' => 'Sukces',
            'warning' => 'Ostrzeżenie',
            'danger' => 'Pilne',
        ][$this->severity] ?? 'Informacja';
    }

    public function occurredAtForHumans(): string
    {
        /** @var Carbon|null $date */
        $date = $this->occurred_at ?: $this->created_at;

        if (! $date) {
            return '—';
        }

        return $date->isToday()
            ? $date->format('H:i')
            : $date->format('d.m.Y H:i');
    }

    public function dashboardUrl(): ?string
    {
        return $this->url ?: null;
    }
}
