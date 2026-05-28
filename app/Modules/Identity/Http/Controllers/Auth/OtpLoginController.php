<?php

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\OtpRequestRequest;
use App\Modules\Identity\Http\Requests\OtpVerifyRequest;
use App\Modules\Identity\Services\OtpAuthService;
use App\Modules\Identity\Services\PostLoginRedirector;
use Illuminate\Http\RedirectResponse;

class OtpLoginController extends Controller
{
    /**
     * Trigger an OTP SMS to the given phone number.
     *
     * Always returns a generic "otp-sent" flash — no user enumeration.
     */
    public function request(OtpRequestRequest $request, OtpAuthService $service): RedirectResponse
    {
        $service->request($request->e164Phone());

        return back()->with('status', 'otp-sent');
    }

    /**
     * Verify the OTP and log the user in on success.
     */
    public function verify(
        OtpVerifyRequest $request,
        OtpAuthService $service,
        PostLoginRedirector $redirector
    ): RedirectResponse {
        $user = $service->verify($request->e164Phone(), $request->validated('code'));

        if (! $user) {
            return back()->withErrors(['code' => __('auth.otp.invalid_code')]);
        }

        return redirect()->to($redirector->resolve($user));
    }
}
