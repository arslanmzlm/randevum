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
use App\Modules\Scheduling\Http\Requests\CancelAppointmentRequest;
use App\Modules\Scheduling\Http\Requests\CheckAvailabilityRequest;
use App\Modules\Scheduling\Http\Requests\DayScheduleRequest;
use App\Modules\Scheduling\Http\Requests\RescheduleAppointmentRequest;
use App\Modules\Scheduling\Http\Requests\StoreAppointmentRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Appointment::class);

        $paginator = $this->service->listForActiveClinic($request->user());

        $doctors = $request->user()->can('appointments.viewAll')
            ? $this->doctorDirectory->activeForClinic()
                ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name])
                ->values()
            : [];

        return Inertia::render('appointments/Index', [
            'appointments' => AppointmentResource::collection($paginator),
            'doctors' => $doctors,
            'query' => FilterHelper::requestState([
                'status' => 'string',
                'doctor_id' => 'integer',
                'start_date' => 'string',
                'end_date' => 'string',
            ]),
            'ownDoctorId' => $request->user()->doctor?->id,
        ]);
    }

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

    public function edit(Request $request, Appointment $appointment): Response
    {
        $this->authorize('update', $appointment);

        $appointment->loadMissing('patient', 'doctor.user', 'service', 'appointmentType');

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $user = $request->user();

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

        return Inertia::render('appointments/Edit', [
            'doctors' => $doctors,
            'services' => $services,
            'appointmentTypes' => $appointmentTypes,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'workingHours' => $clinic->working_hours,
            'timezone' => $clinic->timezone,
            'ownDoctorId' => $user->doctor?->id,
            'appointment' => [
                'id' => $appointment->id,
                'patient' => [
                    'id' => $appointment->patient_id,
                    'full_name' => trim($appointment->patient->first_name.' '.$appointment->patient->last_name),
                    'phone' => $appointment->patient->getRawOriginal('phone'),
                ],
                'doctor_id' => $appointment->doctor_id,
                'service_id' => $appointment->service_id,
                'appointment_type_id' => $appointment->appointment_type_id,
                'duration_minutes' => (int) $appointment->starts_at->diffInMinutes($appointment->ends_at),
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'status' => $appointment->status->value,
                'is_walk_in' => $appointment->is_walk_in,
            ],
        ]);
    }

    public function update(RescheduleAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $this->service->reschedule($appointment, $request->validated(), $request->user());

        Toast::success(__('appointment.rescheduled'));

        return to_route('appointments.index');
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('cancel', $appointment);

        $validated = $request->validated();
        $this->service->cancel($appointment, $validated['reason'] ?? null, $request->user());

        Toast::success(__('appointment.cancelled'));

        return to_route('appointments.index');
    }

    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('delete', $appointment);

        $this->service->delete($appointment, $request->user());

        Toast::success(__('appointment.deleted'));

        return to_route('appointments.index');
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
            isset($validated['exclude_appointment_id']) ? (int) $validated['exclude_appointment_id'] : null,
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
