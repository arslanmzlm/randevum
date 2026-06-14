<?php

namespace App\Modules\Scheduling;

use App\Modules\Core\Contracts\AppointmentCancellationContract;
use App\Modules\Core\Contracts\AppointmentLifecycleContract;
use App\Modules\Core\Contracts\PatientAppointmentsContract;
use App\Modules\Core\Contracts\UpcomingAppointmentsContract;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Scheduling\Listeners\ProvisionDefaultAppointmentTypes;
use App\Modules\Scheduling\Services\AppointmentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AppointmentCancellationContract::class, AppointmentService::class);
        $this->app->bind(AppointmentLifecycleContract::class, AppointmentService::class);
        $this->app->bind(PatientAppointmentsContract::class, AppointmentService::class);
        $this->app->bind(UpcomingAppointmentsContract::class, AppointmentService::class);
    }

    public function boot(): void
    {
        Event::listen(ClinicRegistered::class, ProvisionDefaultAppointmentTypes::class);
    }
}
