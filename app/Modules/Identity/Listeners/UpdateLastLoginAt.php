<?php

namespace App\Modules\Identity\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Stamps `last_login_at` on the user after every successful login.
 * Covers both the email+password path (Fortify) and the OTP path
 * (Auth::guard('web')->login()), since both fire the Login event.
 */
class UpdateLastLoginAt
{
    public function handle(Login $event): void
    {
        $event->user->last_login_at = now();
        $event->user->saveQuietly();
    }
}
