<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Enums\TreatmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Core\Contracts\PatientAppointmentsContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Http\Requests\StorePatientRequest;
use App\Modules\Medical\Http\Requests\UpdatePatientNotesRequest;
use App\Modules\Medical\Http\Requests\UpdatePatientRequest;
use App\Modules\Medical\Http\Resources\PatientResource;
use App\Modules\Medical\Http\Resources\PatientSearchResource;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Modules\Medical\Services\PatientService;
use App\Modules\Medical\Services\TreatmentService;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(
        private PatientService $patientService,
        private TreatmentService $treatmentService,
        private CaseRepository $caseRepository,
        private PatientAppointmentsContract $patientAppointments,
        private BalanceReaderContract $balanceReader,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Patient::class);

        $paginator = $this->patientService->listForActiveClinic();

        return Inertia::render('patients/Index', [
            'patients' => PatientResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'gender' => 'string',
                'is_legacy' => 'boolean',
            ]),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $q = $request->string('q')->value();

        return PatientSearchResource::collection(
            $this->patientService->search($q)
        )->response($request);
    }

    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        return Inertia::render('patients/Create');
    }

    public function store(StorePatientRequest $request): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        try {
            $patient = $this->patientService->create($request->validated());
        } catch (TrashedPhoneConflictException $e) {
            return redirect()->back()->withInput()->with('restorable_patient', [
                'id' => $e->patient->id,
                'full_name' => $e->patient->first_name.' '.$e->patient->last_name,
            ]);
        }

        Toast::success(__('messages.patient.created'));

        return redirect()->route('patients.show', $patient);
    }

    public function show(Request $request, Patient $patient): Response
    {
        $this->authorize('view', $patient);

        $user = $request->user();

        $treatmentCollection = $this->treatmentService->listForPatient($patient, $user);

        $treatments = $treatmentCollection->map(fn ($t) => [
            'id' => $t->id,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'status' => $t->status->value,
            'title' => $t->serviceLines->first()?->service?->name,
            'total_amount' => $t->total_amount,
            'doctor_name' => $t->doctor->display_name,
            'doctor_id' => (int) $t->doctor_id,
            'case_id' => $t->case_id !== null ? (int) $t->case_id : null,
            'case_title' => $t->case?->title,
        ]);

        $doctorId = $user->can('cases.viewAll') ? null : $user->doctor?->id;

        $cases = $this->caseRepository->forPatient($patient->id, $doctorId)
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'status' => $c->status->value,
                'treatments_count' => (int) $c->treatments_count,
                'opened_at' => $c->opened_at->toIso8601String(),
                'follow_up_date' => $c->follow_up_date?->format('Y-m-d'),
            ]);

        $appointments = $user->can('appointments.viewAny')
            ? $this->patientAppointments->listForPatient($patient, $user)
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'starts_at' => $a->starts_at->toIso8601String(),
                    'ends_at' => $a->ends_at->toIso8601String(),
                    'status' => $a->status->value,
                    'is_walk_in' => $a->is_walk_in,
                    'doctor_name' => $a->doctor->display_name,
                    'service_name' => $a->service?->name,
                    'appointment_type' => $a->appointmentType
                        ? ['name' => $a->appointmentType->name, 'color' => $a->appointmentType->color]
                        : null,
                ])
                ->values()
            : collect();

        $props = [
            'patient' => (new PatientResource($patient))->resolve(),
            'treatments' => $treatments,
            'cases' => $cases,
            'appointments' => $appointments,
            'ownDoctorId' => $user->doctor?->id,
        ];

        if ($user->can('transactions.viewAny')) {
            // Total billed = sum of total_amount over the patient's Completed treatments.
            // Scoped to what listForPatient returned (respects treatments.viewAll gate).
            $total = $treatmentCollection
                ->filter(fn ($t) => $t->status === TreatmentStatus::Completed)
                ->reduce(fn ($carry, $t) => bcadd($carry, (string) $t->total_amount, 2), '0.00');

            $paid = $this->balanceReader->paidTotalForPatient($patient->id);

            $props['balance'] = [
                'total' => $total,
                'paid' => $paid,
                'remaining' => bcsub($total, $paid, 2),
            ];

            $props['transactions'] = $this->balanceReader->transactionsForPatient($patient->id);
        }

        return Inertia::render('patients/Show', $props);
    }

    public function edit(Patient $patient): Response
    {
        $this->authorize('update', $patient);

        return Inertia::render('patients/Edit', [
            'patient' => (new PatientResource($patient))->resolve(),
        ]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $this->patientService->update($patient, $request->validated());

        Toast::success(__('messages.patient.updated'));

        return redirect()->route('patients.show', $patient);
    }

    public function destroy(Patient $patient): RedirectResponse
    {
        $this->authorize('delete', $patient);

        $this->patientService->delete($patient);

        Toast::success(__('messages.patient.deleted'));

        return redirect()->route('patients.index');
    }

    public function updateNotes(UpdatePatientNotesRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('updateNotes', $patient);

        $this->patientService->updateNotes($patient, $request->validated()['notes'] ?? null);

        Toast::success(__('messages.patient.notes_updated'));

        return redirect()->route('patients.show', $patient);
    }

    public function restore(int $patient): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        $patientModel = Patient::onlyTrashed()
            ->where('clinic_id', $this->clinicContext->id())
            ->findOrFail($patient);

        $this->patientService->restore($patientModel);

        Toast::success(__('messages.patient.restored'));

        return redirect()->route('patients.show', $patientModel);
    }
}
