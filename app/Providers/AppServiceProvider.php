<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Policies\AppointmentPolicy;
use App\Policies\CasePolicy;
use App\Policies\ClinicPolicy;
use App\Policies\ClinicSmsSettingPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\TreatmentPolicy;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClinicContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureMorphMap();
    }

    /**
     * Restrict the admin-only dashboards (Pulse) to superadmins and register policies.
     */
    protected function configureGates(): void
    {
        Gate::define('viewPulse', fn (User $user): bool => $user->hasRole('superadmin'));
        Gate::policy(Clinic::class, ClinicPolicy::class);
        Gate::policy(ClinicSmsSetting::class, ClinicSmsSettingPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(Treatment::class, TreatmentPolicy::class);
        Gate::policy(CaseRecord::class, CasePolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
    }

    /**
     * Non-enforcing morph map so status_logs (and future polymorphic tables) persist
     * slug strings instead of class names. Non-enforcing so Spatie MediaLibrary's
     * class-name model_type rows remain unaffected.
     */
    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'appointment' => Appointment::class,
            'treatment' => Treatment::class,
            'case' => CaseRecord::class,
            'transaction' => Transaction::class,
            'podiatry' => PodiatryTreatmentDetail::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
