<?php

namespace App\Modules\Medical;

use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Medical\Contracts\FollowUpRemindersContract;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Contracts\TreatmentReaderContract;
use App\Modules\Medical\Listeners\ProvisionDefaultFollowUpTypes;
use App\Modules\Medical\Services\FollowUpReminderService;
use App\Modules\Medical\Services\PatientRegistrar;
use App\Modules\Medical\Services\TreatmentReader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MedicalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PatientRegistrarContract::class, PatientRegistrar::class);
        $this->app->bind(FollowUpRemindersContract::class, FollowUpReminderService::class);
        $this->app->bind(TreatmentReaderContract::class, TreatmentReader::class);
    }

    public function boot(): void
    {
        Event::listen(ClinicRegistered::class, ProvisionDefaultFollowUpTypes::class);
    }
}
