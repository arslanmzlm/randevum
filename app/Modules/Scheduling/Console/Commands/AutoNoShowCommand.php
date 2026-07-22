<?php

namespace App\Modules\Scheduling\Console\Commands;

use App\Modules\Scheduling\Services\AppointmentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoNoShowCommand extends Command
{
    protected $signature = 'appointments:auto-no-show';

    protected $description = 'Sweep every clinic with auto-no-show enabled and transition past, untouched Confirmed/Rescheduled appointments to NoShow.';

    public function handle(AppointmentService $service): int
    {
        $count = $service->autoMarkNoShows(Carbon::now());

        $this->info("Appointments auto-marked as no-show: {$count}");

        return self::SUCCESS;
    }
}
