<?php

namespace App\Modules\Identity\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Stamps `last_login_at` on the user after every successful login.
 * Covers both the email+password path (Fortify) and the OTP path
 * (Auth::guard('web')->login()), since both fire the Login event.
 *
 * On a user's very first login (last_login_at was null before this stamp),
 * a session flag is flashed so the frontend can prompt a password change.
 */
class UpdateLastLoginAt
{
    public function handle(Login $event): void
    {
        $isFirstLogin = $event->user->last_login_at === null;

        $event->user->last_login_at = now();
        $event->user->saveQuietly();

        if ($isFirstLogin) {
            session()->flash('password_reminder', true);
        }
    }
}
