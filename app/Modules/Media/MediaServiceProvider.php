<?php

namespace App\Modules\Media;

use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Services\MediaService;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaServiceContract::class, MediaService::class);
    }
}
