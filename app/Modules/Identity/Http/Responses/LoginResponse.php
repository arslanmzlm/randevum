<?php

namespace App\Modules\Identity\Http\Responses;

use App\Modules\Identity\Services\PostLoginRedirector;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Custom Fortify LoginResponse that redirects to the role-appropriate destination
 * instead of the generic `config('fortify.home')`.
 */
class LoginResponse implements LoginResponseContract
{
    public function __construct(private PostLoginRedirector $redirector) {}

    public function toResponse($request): RedirectResponse
    {
        return redirect()->to($this->redirector->resolve($request->user()));
    }
}
