<?php

namespace App\Modules\Medical;

use App\Modules\Core\Contracts\FollowUpRemindersContract;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Contracts\TreatmentReaderContract;
use App\Modules\Medical\Services\FollowUpReminderService;
use App\Modules\Medical\Services\PatientService;
use App\Modules\Medical\Services\TreatmentReader;
use Illuminate\Support\ServiceProvider;

class MedicalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PatientRegistrarContract::class, PatientService::class);
        $this->app->bind(FollowUpRemindersContract::class, FollowUpReminderService::class);
        $this->app->bind(TreatmentReaderContract::class, TreatmentReader::class);
    }
}
