<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\FollowUp;
use App\Models\FollowUpType;
use App\Models\Patient;
use App\Models\PatientSegment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Product;
use App\Models\Role;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\SmsLog;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Policies\AppointmentPolicy;
use App\Policies\AppointmentTypePolicy;
use App\Policies\CasePolicy;
use App\Policies\ClinicPolicy;
use App\Policies\ClinicSmsSettingPolicy;
use App\Policies\DoctorPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FollowUpPolicy;
use App\Policies\FollowUpTypePolicy;
use App\Policies\PatientPolicy;
use App\Policies\PatientSegmentPolicy;
use App\Policies\PaymentPlanPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RolePolicy;
use App\Policies\ScheduleExceptionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SmsLogPolicy;
use App\Policies\TagPolicy;
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
        // Scoped, not transient: the membership set is asked for by SetClinicContext, the
        // Inertia share and the controller layer within one request, and the service memoizes it.
        $this->app->scoped(ClinicMembershipService::class);
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

        // Every policy registered explicitly so the model→policy map is auditable in one
        // place (auto-discovery would also resolve all but CaseRecord→CasePolicy, whose
        // names don't match).
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(AppointmentType::class, AppointmentTypePolicy::class);
        Gate::policy(CaseRecord::class, CasePolicy::class);
        Gate::policy(Clinic::class, ClinicPolicy::class);
        Gate::policy(ClinicSmsSetting::class, ClinicSmsSettingPolicy::class);
        Gate::policy(Doctor::class, DoctorPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(FollowUp::class, FollowUpPolicy::class);
        Gate::policy(FollowUpType::class, FollowUpTypePolicy::class);
        Gate::policy(Patient::class, PatientPolicy::class);
        Gate::policy(PatientSegment::class, PatientSegmentPolicy::class);
        Gate::policy(PaymentPlan::class, PaymentPlanPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ScheduleException::class, ScheduleExceptionPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(SmsLog::class, SmsLogPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(Treatment::class, TreatmentPolicy::class);
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
            'patient' => Patient::class,
            'user' => User::class,
            'podiatry' => PodiatryTreatmentDetail::class,
            'payment_plan' => PaymentPlan::class,
            'payment_plan_installment' => PaymentPlanInstallment::class,
            'follow_up' => FollowUp::class,
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
