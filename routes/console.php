<?php

use App\Jobs\DeliverPendingNotifications;
use App\Jobs\DetectInventoryRisks;
use App\Jobs\DetectOrderDelays;
use App\Jobs\DetectPurchaseRisks;
use App\Jobs\ProcessPendingFiscalDocuments;
use App\Jobs\QueueAlertNotifications;
use App\Jobs\SynchronizePendingPaymentGatewayTransactions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DetectInventoryRisks)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new DetectOrderDelays)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new DetectPurchaseRisks)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new QueueAlertNotifications)->everyMinute()->withoutOverlapping();
Schedule::job(new DeliverPendingNotifications)->everyMinute()->withoutOverlapping();
Schedule::job(new SynchronizePendingPaymentGatewayTransactions)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ProcessPendingFiscalDocuments)->everyFiveMinutes()->withoutOverlapping();
