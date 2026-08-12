<?php

use App\Console\Commands\CheckExpiringCertificatesCommand;
use App\Console\Commands\SyncReplicaMetricsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SPEC.md §11: renewal runs on a schedule, ahead of expiry — first scheduled task in this app.
Schedule::command(CheckExpiringCertificatesCommand::class)->daily();

// SPEC.md §12/§9.2: Laravel's scheduler has no tighter resolution than every minute, so an
// offline flip can lag the real heartbeat TTL by up to ~60-100s — acceptable for observability
// metrics, not a real-time liveness check.
Schedule::command(SyncReplicaMetricsCommand::class)->everyMinute();
