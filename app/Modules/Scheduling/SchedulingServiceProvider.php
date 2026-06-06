<?php

namespace App\Modules\Scheduling;

use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Scheduling\Listeners\ProvisionDefaultAppointmentTypes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ClinicRegistered::class, ProvisionDefaultAppointmentTypes::class);
    }
}
