<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Product;
use App\Models\Treatment;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Catalog\Contracts\ServiceLookupContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\CompleteTreatmentRequest;
use App\Modules\Medical\Http\Resources\TreatmentProcessResource;
use App\Modules\Medical\Http\Resources\TreatmentShowResource;
use App\Modules\Medical\Services\CaseService;
use App\Modules\Medical\Services\TreatmentService;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TreatmentController extends Controller
{
    public function __construct(
        private TreatmentService $treatmentService,
        private CaseService $caseService,
        private ServiceLookupContract $serviceLookup,
        private ClinicContext $clinicContext,
        private BalanceReaderContract $balanceReader,
    ) {}

    /**
     * POST /appointments/{appointment}/treatment
     * Creates a Draft treatment (or returns the existing one).
     * Redirects to Process screen for Draft; to Show page for Completed.
     */
    public function start(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('create', [Treatment::class, $appointment]);

        $treatment = $this->treatmentService->start($appointment, $request->user());

        if ($treatment->status->value === 'completed') {
            return redirect()->route('treatments.show', $treatment);
        }

        return redirect()->route('treatments.process', $treatment);
    }

    /**
     * GET /treatments/{treatment}/process
     * Renders the Process screen for a Draft treatment.
     */
    public function process(Request $request, Treatment $treatment): Response|RedirectResponse
    {
        $this->authorize('process', $treatment);

        if ($treatment->status->value !== 'draft') {
            return redirect()->route('treatments.show', $treatment);
        }

        $treatment->load(['appointment.appointmentType', 'patient', 'doctor.user', 'details']);

        $clinic = $this->clinicContext->clinicOrFail();

        $services = $this->serviceLookup->activeForTreatment();

        $products = Product::active()
            ->select(['id', 'name', 'price', 'unit', 'current_stock'])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->price,
                'unit' => $p->unit,
                'current_stock' => $p->current_stock,
            ]);

        $openCases = $this->caseService->openCasesForProcess($treatment->patient_id, $treatment->doctor_id);

        $appointmentTypes = AppointmentType::active()
            ->orderBy('name')
            ->get()
            ->map(fn (AppointmentType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'color' => $type->color,
            ]);

        return Inertia::render('treatments/Process', [
            'treatment' => (new TreatmentProcessResource($treatment))->resolve(),
            'services' => $services,
            'products' => $products,
            'openCases' => $openCases,
            'appointmentTypes' => $appointmentTypes,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'workingHours' => $clinic->working_hours,
        ]);
    }

    /**
     * PUT /treatments/{treatment}/complete
     * Completes a Draft treatment atomically.
     */
    public function complete(CompleteTreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $this->authorize('complete', $treatment);

        $validated = $request->validated();

        // Sub-actions of completion are gated only when the payload actually triggers them.
        if (! empty($validated['payments'])) {
            $this->authorize('transactions.create');
        }

        if (($validated['case_mode'] ?? 'none') === 'new') {
            $this->authorize('cases.create');
        }

        if (($validated['follow_up']['mode'] ?? 'none') !== 'none') {
            $this->authorize('appointments.create');
        }

        $result = $this->treatmentService->complete($treatment, $validated, $request->user());

        $created = count($result['created']);
        $skipped = count($result['skipped']);

        if ($created > 0 || $skipped > 0) {
            Toast::success(__('treatment.completed_with_followups', [
                'created' => $created,
                'skipped' => $skipped,
            ]));
        } else {
            Toast::success(__('treatment.completed'));
        }

        return redirect()->route('treatments.show', $treatment);
    }

    /**
     * GET /treatments/{treatment}
     * Show page for a completed (or any status) treatment.
     */
    public function show(Request $request, Treatment $treatment): Response
    {
        $this->authorize('view', $treatment);

        $treatment->load([
            'appointment',
            'patient',
            'doctor.user',
            'case',
            'details',
            'serviceLines.service',
            'productLines.product',
            'transactions',
        ]);

        $data = (new TreatmentShowResource($treatment))->resolve();

        // Transaction list is Billing-owned; pull it through the contract, gated like the patient page.
        if ($request->user()?->can('transactions.viewAny')) {
            $data['transactions'] = $this->balanceReader->transactionsForTreatment($treatment->id);
        }

        return Inertia::render('treatments/Show', [
            'treatment' => $data,
        ]);
    }
}
