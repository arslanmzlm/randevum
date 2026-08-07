<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Modules\Scheduling\Http\Requests\CalendarEventsRequest;
use App\Modules\Scheduling\Services\CalendarService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function __construct(
        private CalendarService $calendarService,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicContext $clinicContext,
        private ClinicMembershipService $membership,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $clinic = $this->clinicContext->clinicOrFail();
        $user = $request->user();

        $doctors = $this->doctorDirectory->activeForClinic()
            ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name]);

        return Inertia::render('calendar/Index', [
            'doctors' => $doctors,
            'ownDoctorId' => $user->doctor?->id,
            'workingHours' => $clinic->working_hours,
            'timezone' => $clinic->timezone,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'defaultView' => 'week',
            'clinics' => $this->availableBranches($user, $clinic->tenant_id),
        ]);
    }

    public function events(CalendarEventsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $clinic = $this->clinicContext->clinicOrFail();
        $validated = $request->validated();
        $tz = $clinic->timezone;

        $startUtc = Carbon::createFromFormat('Y-m-d', $validated['start'], $tz)->startOfDay()->utc();
        $endUtc = Carbon::createFromFormat('Y-m-d', $validated['end'], $tz)->endOfDay()->utc();

        $statuses = array_map(
            fn (string $s) => AppointmentStatus::from($s),
            $validated['statuses'],
        );

        $doctorIds = array_map(intval(...), $validated['doctor_id'] ?? []);

        $clinicIds = array_map(intval(...), $validated['clinic_id'] ?? []);
        $clinicIds = $clinicIds === [] ? [$clinic->id] : array_values($clinicIds);

        $result = $this->calendarService->eventsFor(
            $request->user(),
            $startUtc,
            $endUtc,
            $doctorIds === [] ? null : array_values($doctorIds),
            $statuses,
            $clinic,
            $clinicIds,
        );

        return response()->json($result);
    }

    /**
     * Branch options for the calendar's multi-branch filter — [] when the user has
     * fewer than 2 memberships within the active clinic's tenant or lacks
     * appointments.viewAll (cross-branch is meaningless for a user confined to
     * their own single doctors row). Intersected with the tenant, not the raw
     * membership set, so a user who also holds clinic-scoped roles at an unrelated
     * tenant never sees (or can request) that tenant's clinics here.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableBranches(User $user, int $tenantId): array
    {
        if (! $user->can('appointments.viewAll')) {
            return [];
        }

        $clinics = $this->membership->clinicsFor($user)->where('tenant_id', $tenantId);

        if ($clinics->count() < 2) {
            return [];
        }

        return $clinics->map(fn ($clinic) => ['id' => $clinic->id, 'name' => $clinic->name])->values()->all();
    }
}
