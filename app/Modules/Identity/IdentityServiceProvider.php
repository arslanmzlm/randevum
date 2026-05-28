<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Responses\LoginResponse;
use App\Modules\Identity\Http\Responses\LogoutResponse;
use App\Modules\Identity\Listeners\UpdateLastLoginAt;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, UpdateLastLoginAt::class);
    }
}
