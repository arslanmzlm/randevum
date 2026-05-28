<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Responses\LoginResponse;
use App\Modules\Identity\Http\Responses\LogoutResponse;
use App\Modules\Identity\Http\Responses\PasswordUpdateResponse;
use App\Modules\Identity\Http\Responses\ProfileInformationUpdatedResponse;
use App\Modules\Identity\Http\Responses\RegisterResponse;
use App\Modules\Identity\Listeners\UpdateLastLoginAt;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileInformationUpdatedResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, UpdateLastLoginAt::class);
    }
}
