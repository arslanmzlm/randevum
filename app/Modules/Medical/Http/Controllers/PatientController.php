<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Http\Requests\StorePatientRequest;
use App\Modules\Medical\Http\Requests\UpdatePatientRequest;
use App\Modules\Medical\Http\Resources\PatientResource;
use App\Modules\Medical\Services\PatientService;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(
        private PatientService $patientService,
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
            'canManage' => $request->user()->can('patients.create'),
            'canDelete' => $request->user()->can('patients.delete'),
        ]);
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

        return Inertia::render('patients/Show', [
            'patient' => (new PatientResource($patient))->resolve(),
            'treatments' => [],
            'canManage' => $request->user()->can('patients.update'),
        ]);
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
