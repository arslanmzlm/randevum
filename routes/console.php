<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:clean')->weekly()->at('02:00')->withoutOverlapping();
Schedule::command('backup:run')->daily()->at('03:00')->withoutOverlapping();
Schedule::command('backup:monitor')->daily()->at('04:00')->withoutOverlapping();

Schedule::command('sms:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:auto-no-show')->hourly()->withoutOverlapping();
// Installments are date-granular, so the every-5-min appointment cadence is unnecessary.
Schedule::command('sms:send-installment-reminders')->dailyAt('09:00')->withoutOverlapping();
