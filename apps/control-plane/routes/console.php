<?php

use App\Console\Commands\CheckExpiringCertificatesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SPEC.md §11: renewal runs on a schedule, ahead of expiry — first scheduled task in this app.
Schedule::command(CheckExpiringCertificatesCommand::class)->daily();
