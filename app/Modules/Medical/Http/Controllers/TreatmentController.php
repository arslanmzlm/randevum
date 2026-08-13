<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Treatment;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Catalog\Contracts\ProductLookupContract;
use App\Modules\Catalog\Contracts\ServiceLookupContract;
use App\Modules\Core\Contracts\AppointmentTypeLookupContract;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Exceptions\TreatmentVoidBlockedException;
use App\Modules\Medical\Http\Requests\CompleteTreatmentRequest;
use App\Modules\Medical\Http\Requests\VoidTreatmentRequest;
use App\Modules\Medical\Http\Resources\AnamnesisResource;
use App\Modules\Medical\Http\Resources\TreatmentListResource;
use App\Modules\Medical\Http\Resources\TreatmentProcessResource;
use App\Modules\Medical\Http\Resources\TreatmentShowResource;
use App\Modules\Medical\Services\AnamnesisFieldService;
use App\Modules\Medical\Services\AnamnesisService;
use App\Modules\Medical\Services\CaseService;
use App\Modules\Medical\Services\TreatmentService;
use App\Modules\Medical\Support\MediaItemMapper;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
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
        private ProductLookupContract $productLookup,
        private AppointmentTypeLookupContract $appointmentTypeLookup,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicContext $clinicContext,
        private BalanceReaderContract $balanceReader,
        private AnamnesisService $anamnesisService,
        private AnamnesisFieldService $anamnesisFieldService,
    ) {}

    /**
     * GET /treatments
     * Server-side paginated clinic-wide treatment list.
     * Visibility mirrors the appointment list — treatments.viewAll → every doctor's
     * treatments, otherwise only the user's own doctor profile's treatments.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Treatment::class);

        $paginator = $this->treatmentService->listForActiveClinic($request->user());

        $canViewAll = $request->user()->can('treatments.viewAll');

        return Inertia::render('treatments/Index', [
            'treatments' => TreatmentListResource::collection($paginator),
            'doctors' => fn () => $canViewAll
                ? $this->doctorDirectory->activeForClinic()
                    ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name])
                    ->values()
                : [],
            'services' => fn () => $this->serviceLookup->activeForTreatment()
                ->map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name']])
                ->values(),
            'query' => FilterHelper::requestState([
                'status' => 'string',
                'doctor_id' => 'array',
                'service_id' => 'string',
                'start_date' => 'string',
                'end_date' => 'string',
            ]),
        ]);
    }

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

        $treatment->load([
            'appointment.appointmentType',
            'patient',
            // withTrashed(): a draft can outlive the doctor's removal — the screen needs the name.
            'doctor' => fn ($q) => $q->withTrashed()->with('user'),
            'media',
        ]);

        $clinic = $this->clinicContext->clinicOrFail();

        $services = $this->serviceLookup->activeForTreatment();

        $products = $this->productLookup->activeForTreatment();

        $openCases = $this->caseService->openCasesForProcess($treatment->patient_id, $treatment->doctor_id);

        $appointmentTypes = $this->appointmentTypeLookup->activeForTreatment();

        $treatmentData = (new TreatmentProcessResource($treatment))->resolve();

        // KVKK-min: treatment media is doctor + assistant only, never owner/manager/
        // receptionist — same gating style as the Show page's transactions block.
        if ($request->user()?->can('treatments.media.view')) {
            $treatmentData['media'] = $treatment->getMedia('treatment_media')
                ->map(fn ($media) => MediaItemMapper::map($treatment, $media))
                ->all();
        }

        $anamnesis = $this->anamnesisService->read($treatment->patient);

        return Inertia::render('treatments/Process', [
            'treatment' => $treatmentData,
            'services' => $services,
            'products' => $products,
            'openCases' => $openCases,
            'appointmentTypes' => $appointmentTypes,
            'defaultSlotDuration' => $clinic->default_slot_duration_minutes,
            'workingHours' => $clinic->working_hours,
            'anamnesis' => $anamnesis !== null ? (new AnamnesisResource($anamnesis))->resolve() : null,
            'anamnesisFields' => $this->anamnesisFieldService->toPropArray(
                $this->anamnesisFieldService->definitionsForActiveClinic()
            ),
            'anamnesisFieldsAll' => $this->anamnesisFieldService->toPropArray(
                $this->anamnesisFieldService->definitionsForActiveClinic(includeInactive: true)
            ),
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
     * POST /treatments/{treatment}/void
     * Marks a completed treatment as never having happened (mis-entry correction).
     * `restock_line_ids` carries the per-product-line "return to stock" choice.
     */
    public function void(VoidTreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $this->authorize('void', $treatment);

        try {
            $this->treatmentService->void($treatment, $request->user(), $request->restockLineIds());
        } catch (TreatmentVoidBlockedException $e) {
            Toast::warning($e->getMessage());

            return back();
        }

        Toast::success(__('treatment.voided'));

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
            // withTrashed(): the treatment outlives a soft-deleted patient (treatments are
            // never deleted) — this detail page stays reachable long after the patient is gone.
            'patient' => fn ($q) => $q->withTrashed(),
            'doctor' => fn ($q) => $q->withTrashed()->with('user'),
            'case',
            'serviceLines.service',
            'productLines.product',
            'transactions',
            'media',
        ]);

        $data = (new TreatmentShowResource($treatment))->resolve();

        // Transaction list is Billing-owned; pull it through the contract, gated like the patient page.
        if ($request->user()?->can('transactions.viewAny')) {
            $data['transactions'] = $this->balanceReader->transactionsForTreatment($treatment->id);
        }

        // KVKK-min: treatment media is doctor + assistant only. Absent/empty → gallery hidden.
        if ($request->user()?->can('treatments.media.view')) {
            $data['media'] = $treatment->getMedia('treatment_media')
                ->map(fn ($media) => MediaItemMapper::map($treatment, $media))
                ->all();
        }

        return Inertia::render('treatments/Show', [
            'treatment' => $data,
        ]);
    }
}
