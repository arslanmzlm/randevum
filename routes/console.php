<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:clean')->weekly()->at('02:00');
Schedule::command('backup:run')->daily()->at('03:00');
Schedule::command('backup:monitor')->daily()->at('04:00');
