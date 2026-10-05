<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FEAT-UPL-003: transient uploads never outlive their TTL.
Schedule::command('dataspeak:purge-uploads')->everyFiveMinutes()->withoutOverlapping();

// NFR-REL-001: a job killed with its worker must not leave an analysis spinning.
Schedule::command('dataspeak:reap-stale-jobs')->everyMinute()->withoutOverlapping();
