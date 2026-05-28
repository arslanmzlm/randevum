<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Core\Support\Toast;
use App\Modules\Identity\Services\PostLoginRedirector;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * Custom Fortify RegisterResponse: redirects the freshly-created owner to
 * their role-appropriate destination (same logic as LoginResponse).
 */
class RegisterResponse implements RegisterResponseContract
{
    public function __construct(private PostLoginRedirector $redirector) {}

    public function toResponse($request): RedirectResponse
    {
        Toast::success(__('auth.register.welcome'));

        return redirect()->to($this->redirector->resolve($request->user()));
    }
}
