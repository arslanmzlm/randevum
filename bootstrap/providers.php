<?php

use App\Modules\Core\CoreServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Media\MediaServiceProvider;
use App\Modules\Medical\MedicalServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Verticals\Podiatry\PodiatryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    CoreServiceProvider::class,
    IdentityServiceProvider::class,
    MedicalServiceProvider::class,
    MediaServiceProvider::class,
    MessagingServiceProvider::class,
    SchedulingServiceProvider::class,
    PodiatryServiceProvider::class,
];
