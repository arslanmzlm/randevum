<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Service;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Scheduling\Http\Requests\CheckAvailabilityRequest;
use App\Modules\Scheduling\Http\Requests\DayScheduleRequest;
use App\Modules\Scheduling\Http\Requests\StoreAppointmentRequest;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentService $service,
        private AppointmentTypeService $appointmentTypeService,
        private AvailabilityService $availabilityService,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicContext $clinicContext,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('create', Appointment::class);

        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $doctors = $this->doctorDirectory->activeForClinic()
            ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name]);

        $services = Service::active()
            ->select(['id', 'name', 'duration_minutes', 'price'])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'duration_minutes' => $s->duration_minutes,
                'price' => $s->price,
            ]);

        $appointmentTypes = $this->appointmentTypeService->listActiveForClinic()
            ->map(fn (AppointmentType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
                'default_duration_minutes' => $t->default_duration_minutes,
            ]);

        $preselectedPatient = null;
        if ($patientId = $request->integer('patient_id')) {
            $patient = Patient::find($patientId);
            if ($patient) {
                $preselectedPatient = [
                    'id' => $patient->id,
                    'full_name' => trim($patient->first_name.' '.$patient->last_name),
                    'phone' => $patient->getRawOriginal('phone'),
                ];
            }
        }

        $user = $request->user();

        return Inertia::render('appointments/Create', [
            'doctors' => $doctors,
            'services' => $services,
            'appointmentTypes' => $appointmentTypes,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'workingHours' => $clinic->working_hours,
            'timezone' => $clinic->timezone,
            'preselectedPatient' => $preselectedPatient,
            // Cross-doctor booking gate: without it the doctor select locks to the user's own profile.
            'canAssignDoctor' => $user->can('appointments.assignDoctor'),
            'ownDoctorId' => $user->doctor?->id,
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        $appointment = $this->service->create($request->validated(), $request->user());

        $appointment->loadMissing('patient');

        $patientName = trim(
            $appointment->patient->first_name.' '.$appointment->patient->last_name
        );
        $slot = $appointment->starts_at
            ->setTimezone($this->activeClinicTimezone())
            ->format('d.m.Y H:i');

        Toast::success(__('appointment.created', ['patient' => $patientName, 'time' => $slot]));

        return to_route('appointments.create');
    }

    /**
     * Read-only pre-check: returns whether a doctor+slot is available and, if not, why.
     * Reuses AvailabilityService — no separate layer logic.
     */
    public function availability(CheckAvailabilityRequest $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $validated = $request->validated();

        $startsAt = Carbon::parse($validated['starts_at'], $clinic->timezone)->utc();
        $duration = $this->availabilityService->resolveDuration(
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null,
            isset($validated['service_id']) ? (int) $validated['service_id'] : null,
            isset($validated['appointment_type_id']) ? (int) $validated['appointment_type_id'] : null,
            $clinic,
        );
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $reason = $this->availabilityService->unavailableReason(
            (int) $validated['doctor_id'],
            $startsAt,
            $endsAt,
            (bool) ($validated['is_walk_in'] ?? false),
            $clinic,
        );

        return response()->json([
            'available' => $reason === null,
            'reason' => $reason?->value,
        ]);
    }

    /**
     * Returns the active clinic's appointments for a given doctor on a given date,
     * formatted for the day-schedule advisory panel on the create form.
     */
    public function daySchedule(DayScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $validated = $request->validated();
        $tz = $clinic->timezone;

        $dayStart = Carbon::createFromFormat('Y-m-d', $validated['date'], $tz)->startOfDay()->utc();
        $dayEnd = $dayStart->copy()->addDay();

        $appointments = Appointment::forDoctor((int) $validated['doctor_id'])
            ->forDay($dayStart, $dayEnd)
            ->with(['patient', 'service', 'appointmentType'])
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $appointments->map(fn (Appointment $a) => [
                'id' => $a->id,
                'start_time' => $a->starts_at->setTimezone($tz)->format('H:i'),
                'end_time' => $a->ends_at->setTimezone($tz)->format('H:i'),
                'status' => $a->status->value,
                'is_walk_in' => $a->is_walk_in,
                'patient_name' => trim($a->patient->first_name.' '.$a->patient->last_name),
                'service_name' => $a->service?->name,
                'appointment_type' => $a->appointmentType
                    ? ['name' => $a->appointmentType->name, 'color' => $a->appointmentType->color]
                    : null,
            ])->values(),
        ]);
    }

    private function activeClinicTimezone(): string
    {
        $clinic = Clinic::find($this->clinicContext->id());

        return $clinic?->timezone ?? 'UTC';
    }
}
