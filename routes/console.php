<?php

use App\Jobs\DetectInventoryRisks;
use App\Jobs\DetectOrderDelays;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DetectInventoryRisks)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new DetectOrderDelays)->everyFifteenMinutes()->withoutOverlapping();
