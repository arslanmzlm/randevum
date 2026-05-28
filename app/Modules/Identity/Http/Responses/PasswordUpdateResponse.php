<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

/**
 * Custom Fortify PasswordUpdateResponse: flashes a success toast
 * then redirects back to the settings page.
 */
class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Toast::success(__('settings.password_updated'));

        return redirect()->back();
    }
}
