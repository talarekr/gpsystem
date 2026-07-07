<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('marketplace:auto-sync-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('marketplace_order_sync.enabled', false));

Schedule::command('allegro:refresh-pending-listings --limit=50 --older-than-minutes=2')
    ->everyFiveMinutes()
    ->withoutOverlapping();
