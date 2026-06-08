<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
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
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $doctors = $this->doctorDirectory->activeForClinic()
            ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name]);

        return Inertia::render('calendar/Index', [
            'doctors' => $doctors,
            'ownDoctorId' => $request->user()->doctor?->id,
            'workingHours' => $clinic->working_hours,
            'timezone' => $clinic->timezone,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'defaultView' => 'week',
        ]);
    }

    public function events(CalendarEventsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $validated = $request->validated();
        $tz = $clinic->timezone;

        $startUtc = Carbon::createFromFormat('Y-m-d', $validated['start'], $tz)->startOfDay()->utc();
        $endUtc = Carbon::createFromFormat('Y-m-d', $validated['end'], $tz)->endOfDay()->utc();

        $statuses = array_map(
            fn (string $s) => AppointmentStatus::from($s),
            $validated['statuses'],
        );

        $result = $this->calendarService->eventsFor(
            $request->user(),
            $startUtc,
            $endUtc,
            isset($validated['doctor_id']) ? (int) $validated['doctor_id'] : null,
            $statuses,
            $clinic,
        );

        return response()->json($result);
    }
}
