<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('drive:sync-unclassified')
    ->hourly()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('drive_sync.enabled', true));

// NOTE: drive:cleanup-orphans stays manual on purpose (never scheduled).
