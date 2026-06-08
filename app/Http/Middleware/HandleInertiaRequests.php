<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
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
            'flash' => [
                'toasts' => fn () => $request->session()->get('toasts', []),
                'password_reminder' => fn () => (bool) $request->session()->get('password_reminder', false),
                'restorable_patient' => fn () => $request->session()->get('restorable_patient'),
            ],
        ];
    }

    /**
     * Active clinic identity for the app shell (sidebar logo + name); null for guests.
     *
     * @return array{id: int, name: string, logo_url: string|null}|null
     */
    private function sharedClinic(): ?array
    {
        $clinicId = app(ClinicContext::class)->id();

        if ($clinicId === null) {
            return null;
        }

        $clinic = Clinic::find($clinicId);

        if ($clinic === null) {
            return null;
        }

        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'logo_url' => $clinic->imageUrl('logo', 'thumb'),
            // Shared globally so useDateTime() and the calendar read tz from one source.
            'timezone' => $clinic->timezone,
        ];
    }
}
