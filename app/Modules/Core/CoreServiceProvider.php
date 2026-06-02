<?php

namespace App\Modules\Core;

use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Services\DoctorDirectoryService;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DoctorDirectoryContract::class, DoctorDirectoryService::class);
    }
}
