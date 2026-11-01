<?php

use App\Console\Commands\RefreshMetalPrices;
use App\Services\Credits\ComplimentaryCreditService;
use App\Services\Credits\CreditWalletService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RefreshMetalPrices::class)->everySixHours();
Schedule::command('subscriptions:reconcile')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('subscriptions:recover-apple')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(ComplimentaryCreditService::class)->allocateDue())->everyMinute()->name('credits-allocate-complimentary')->withoutOverlapping();
Schedule::call(fn () => app(CreditWalletService::class)->expireDue())->everyMinute()->name('credits-expire')->withoutOverlapping();
Schedule::command('stripe:recover-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('stripe:reconcile-orders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('stripe:audit-wallet')->dailyAt('03:00')->withoutOverlapping();
