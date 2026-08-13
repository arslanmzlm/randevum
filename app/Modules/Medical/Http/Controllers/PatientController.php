<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Core\Contracts\PatientAppointmentsContract;
use App\Modules\Core\Exceptions\DeletionBlockedException;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Http\Requests\StorePatientRequest;
use App\Modules\Medical\Http\Requests\SyncPatientTagsRequest;
use App\Modules\Medical\Http\Requests\UpdatePatientNotesRequest;
use App\Modules\Medical\Http\Requests\UpdatePatientRequest;
use App\Modules\Medical\Http\Resources\AnamnesisResource;
use App\Modules\Medical\Http\Resources\PatientResource;
use App\Modules\Medical\Http\Resources\PatientSearchResource;
use App\Modules\Medical\Services\AnamnesisFieldService;
use App\Modules\Medical\Services\AnamnesisService;
use App\Modules\Medical\Services\CaseService;
use App\Modules\Medical\Services\PatientService;
use App\Modules\Medical\Services\SegmentService;
use App\Modules\Medical\Services\TagService;
use App\Modules\Medical\Services\TreatmentService;
use App\Modules\Messaging\Contracts\SmsHistoryContract;
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
        private CaseService $caseService,
        private PatientAppointmentsContract $patientAppointments,
        private BalanceReaderContract $balanceReader,
        private SmsHistoryContract $smsHistory,
        private ClinicContext $clinicContext,
        private TagService $tagService,
        private SegmentService $segmentService,
        private AnamnesisService $anamnesisService,
        private AnamnesisFieldService $anamnesisFieldService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Patient::class);

        $paginator = $this->patientService->listForActiveClinic();

        // Capture ids before PatientResource::collection() maps the paginator's items to resources.
        $ids = array_map(static fn (Patient $p): int => (int) $p->id, $paginator->items());

        $props = [
            'patients' => PatientResource::collection($paginator),
            'tags' => $this->tagService->listOptionsForActiveClinic(),
            'segments' => $this->segmentService->listForActiveClinic()->map(fn ($segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'criteria' => $segment->criteria,
            ])->values(),
            'query' => FilterHelper::requestState([
                'gender' => 'string',
                'is_legacy' => 'boolean',
                'tags' => 'array',
                'last_visit_after' => 'date',
                'last_visit_before' => 'date',
            ]),
        ];

        // Balance column is money — gated by the same permission as the rest of the figures.
        if ($request->user()->can('transactions.viewAny')) {
            $props['balances'] = $this->patientService->remainingBalancesFor($ids);
        }

        return Inertia::render('patients/Index', $props);
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

        $patient->loadMissing('tags');

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

        $cases = $this->caseService->casesForPatient($patient->id, $user);

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

        $smsLogs = $this->smsHistory->recentForPatient($patient)
            ->map(fn (SmsLog $log) => [
                'id' => $log->id,
                'type' => $log->type->value,
                'status' => $log->status->value,
                'phone' => $log->phone,
                'patient_name' => null,
                'patient_id' => $log->patient_id,
                'body' => $log->body,
                'error' => $log->error,
                'created_at' => $log->created_at->toIso8601String(),
                'sent_at' => $log->sent_at?->toIso8601String(),
            ])
            ->values();

        $props = [
            'patient' => (new PatientResource($patient))->resolve(),
            'treatments' => $treatments,
            'cases' => $cases,
            'appointments' => $appointments,
            'smsLogs' => $smsLogs,
            'ownDoctorId' => $user->doctor?->id,
            'allTags' => $this->tagService->listOptionsForActiveClinic(),
            'anamnesis' => ($anamnesis = $this->anamnesisService->read($patient)) !== null
                ? (new AnamnesisResource($anamnesis))->resolve()
                : null,
            'anamnesisFields' => $this->anamnesisFieldService->toPropArray(
                $this->anamnesisFieldService->definitionsForActiveClinic()
            ),
            // Active + inactive, so a retired definition's stored value still labels a row in
            // the summary card (it already renders in the PDF via allForVertical()).
            'anamnesisFieldsAll' => $this->anamnesisFieldService->toPropArray(
                $this->anamnesisFieldService->definitionsForActiveClinic(includeInactive: true)
            ),
        ];

        if ($user->can('transactions.viewAny')) {
            $props['balance'] = $this->patientService->balanceForPatient($patient, $treatmentCollection);
            $props['transactions'] = $this->balanceReader->transactionsForPatient($patient->id);
            $props['paymentPlans'] = $this->balanceReader->paymentPlansForPatient($patient->id);
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

        try {
            $this->patientService->delete($patient);
        } catch (DeletionBlockedException $e) {
            Toast::warning($e->getMessage());

            return back();
        }

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

    public function syncTags(SyncPatientTagsRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $patient->tags()->sync($request->validated('tag_ids', []));

        Toast::success(__('messages.tag.synced'));

        return redirect()->route('patients.show', $patient);
    }
}
