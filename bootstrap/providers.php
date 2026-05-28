<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Verticals\Podiatry\PodiatryServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    MessagingServiceProvider::class,
    PodiatryServiceProvider::class,
];
