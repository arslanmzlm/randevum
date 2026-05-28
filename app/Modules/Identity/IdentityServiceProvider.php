<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Responses\LoginResponse;
use App\Modules\Identity\Http\Responses\LogoutResponse;
use App\Modules\Identity\Http\Responses\RegisterResponse;
use App\Modules\Identity\Listeners\UpdateLastLoginAt;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, UpdateLastLoginAt::class);
    }
}
