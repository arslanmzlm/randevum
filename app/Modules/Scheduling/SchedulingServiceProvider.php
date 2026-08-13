<?php

namespace App\Modules\Scheduling;

use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Scheduling\Console\Commands\AutoNoShowCommand;
use App\Modules\Scheduling\Console\Commands\SendRemindersCommand;
use App\Modules\Scheduling\Contracts\AppointmentCancellationContract;
use App\Modules\Scheduling\Contracts\AppointmentLifecycleContract;
use App\Modules\Scheduling\Contracts\AppointmentTypeLookupContract;
use App\Modules\Scheduling\Contracts\DashboardStatsContract;
use App\Modules\Scheduling\Contracts\PatientAppointmentCounterContract;
use App\Modules\Scheduling\Contracts\PatientAppointmentsContract;
use App\Modules\Scheduling\Contracts\UpcomingAppointmentsContract;
use App\Modules\Scheduling\Listeners\ProvisionDefaultAppointmentTypes;
use App\Modules\Scheduling\Services\AppointmentReader;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Modules\Scheduling\Services\DashboardStatsService;
use App\Modules\Scheduling\Services\PatientAppointmentCounter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AppointmentCancellationContract::class, AppointmentService::class);
        $this->app->bind(AppointmentLifecycleContract::class, AppointmentService::class);
        $this->app->bind(PatientAppointmentsContract::class, AppointmentReader::class);
        $this->app->bind(PatientAppointmentCounterContract::class, PatientAppointmentCounter::class);
        $this->app->bind(UpcomingAppointmentsContract::class, AppointmentReader::class);
        $this->app->bind(DashboardStatsContract::class, DashboardStatsService::class);
        $this->app->bind(AppointmentTypeLookupContract::class, AppointmentTypeService::class);
    }

    public function boot(): void
    {
        Event::listen(ClinicRegistered::class, ProvisionDefaultAppointmentTypes::class);

        $this->commands([SendRemindersCommand::class, AutoNoShowCommand::class]);
    }
}
