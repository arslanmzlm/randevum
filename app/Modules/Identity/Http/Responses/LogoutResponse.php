<?php

namespace App\Modules\Identity\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * Custom Fortify LogoutResponse that sends users back to the login page.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->route('login');
    }
}
