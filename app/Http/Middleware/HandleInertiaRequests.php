<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Models\User;
use App\Modules\Core\Contracts\UpcomingAppointmentsContract;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Support\ClinicContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'isDoctor' => fn () => $request->user()?->doctor !== null,
                // Active-clinic-scoped permission names (team id set by SetClinicContext). The
                // frontend reads these via useCan(); server authorize() still enforces. Ownership/
                // instance checks (e.g. own doctor profile) stay as per-page props, not here.
                'permissions' => fn () => $request->user()?->getAllPermissions()->pluck('name')->all() ?? [],
            ],
            'activeClinic' => fn () => $this->sharedClinic(),
            'availableClinics' => fn () => $this->sharedAvailableClinics($request),
            'upcomingAppointments' => fn () => $this->sharedUpcomingAppointments($request),
            'flash' => [
                'toasts' => fn () => $request->session()->get('toasts', []),
                'password_reminder' => fn () => (bool) $request->session()->get('password_reminder', false),
                'restorable_patient' => fn () => $request->session()->get('restorable_patient'),
            ],
        ];
    }

    /**
     * Upcoming appointments for the header widget; [] for guests or no permission.
     * Clinic context is already set by SetClinicContext, so can() is clinic-scoped.
     *
     * @return list<array<string, mixed>>
     */
    private function sharedUpcomingAppointments(Request $request): array
    {
        $user = $request->user();

        if ($user === null || ! $user->can('appointments.viewAny')) {
            return [];
        }

        return app(UpcomingAppointmentsContract::class)
            ->upcomingFor($user, config('platform.appointment.upcoming_widget_limit'));
    }

    /**
     * Clinics the user can switch into (branch switcher). [] for guests, users with a
     * single membership, and users who hold clinics.switch nowhere — the switcher is
     * hidden entirely in every one of those cases (single-branch behaviour unchanged).
     *
     * @return list<array{id: int, name: string}>
     */
    private function sharedAvailableClinics(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        $membership = app(ClinicMembershipService::class);
        $clinics = $membership->clinicsFor($user);

        if ($clinics->count() < 2 || $membership->switchableClinicIds($user) === []) {
            return [];
        }

        // Filtered by the same predicate ClinicPolicy::switchTo enforces, so a
        // listed entry can never 403 on click (e.g. active=C not switchable,
        // switchable=[A] only → B must not appear even though it's a membership).
        $targetIds = $membership->switchTargetsFor($user, app(ClinicContext::class)->id());

        return $clinics->whereIn('id', $targetIds)
            ->map(fn (Clinic $clinic): array => [
                'id' => $clinic->id,
                'name' => $clinic->name,
            ])->values()->all();
    }

    /**
     * Active clinic identity for the app shell (sidebar logo + name); null for guests.
     *
     * @return array{id: int, name: string, logo_url: string|null, logo_dark_url: string|null, logo_icon_url: string|null, timezone: string, currency: string, vertical: array{slug: string|null}}|null
     */
    private function sharedClinic(): ?array
    {
        $clinicId = app(ClinicContext::class)->id();

        if ($clinicId === null) {
            return null;
        }

        $clinic = Clinic::with('vertical')->find($clinicId);

        if ($clinic === null) {
            return null;
        }

        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'logo_url' => $clinic->imageUrl('logo', 'thumb'),
            'logo_dark_url' => $clinic->logoUrl('logo_dark', 'thumb'),
            'logo_icon_url' => $clinic->logoUrl('logo_icon', 'thumb'),
            // Shared globally so useDateTime() and the calendar read tz from one source.
            'timezone' => $clinic->timezone,
            // ISO 4217 code — single source for client-side money formatting (useMoney()).
            'currency' => $clinic->currency,
            // Vertical slug — gates vertical-specific UI (e.g. podiatry anamnesis section).
            'vertical' => [
                'slug' => $clinic->vertical?->slug,
            ],
        ];
    }
}
