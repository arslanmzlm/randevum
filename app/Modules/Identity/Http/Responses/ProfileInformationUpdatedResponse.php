<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;

/**
 * Custom Fortify ProfileInformationUpdatedResponse: flashes a success toast
 * then redirects back so the Inertia page refreshes with updated auth.user.
 */
class ProfileInformationUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        Toast::success(__('settings.profile_updated'));

        return redirect()->back();
    }
}
