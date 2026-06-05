<?php

namespace App\Modules\Medical;

use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Services\PatientService;
use Illuminate\Support\ServiceProvider;

class MedicalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PatientRegistrarContract::class, PatientService::class);
    }
}
