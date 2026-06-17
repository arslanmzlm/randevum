<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\LegalDocument;
use App\Models\Vertical;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Modules\Identity\Actions\RegisterClinicOwner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(RegisterClinicOwner::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(function () {
            return Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canLoginWithOtp' => true,
                'status' => session('status'),
            ]);
        });

        Fortify::registerView(function () {
            $verticals = Vertical::where('is_active', true)
                ->get()
                ->map(fn (Vertical $v): array => [
                    'id' => $v->id,
                    'slug' => $v->slug,
                    'name' => __('verticals.'.$v->slug.'::vertical.name'),
                ])
                ->values()
                ->all();

            $legalDocuments = collect(app(ConsentRecorderContract::class)->activeRegistrationDocuments())
                ->map(fn (LegalDocument $document): array => [
                    'type' => $document->type->value,
                    'title' => $document->title,
                    'version' => $document->version,
                    'content' => $document->content,
                ])
                ->all();

            return Inertia::render('auth/Register', [
                'verticals' => $verticals,
                'legalDocuments' => $legalDocuments,
                'status' => session('status'),
            ]);
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('otp-request', function (Request $request) {
            return Limit::perMinute(1)->by($request->input('phone').'|'.$request->ip());
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinute(6)->by($request->input('phone').'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
