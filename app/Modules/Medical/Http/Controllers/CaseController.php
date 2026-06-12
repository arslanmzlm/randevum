<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Enums\CaseStatus;
use App\Enums\TreatmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\Treatment;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\ChangeCaseStatusRequest;
use App\Modules\Medical\Http\Requests\LinkCaseTreatmentsRequest;
use App\Modules\Medical\Http\Requests\StoreCaseRequest;
use App\Modules\Medical\Http\Requests\UpdateCaseFollowUpRequest;
use App\Modules\Medical\Http\Requests\UpdateCaseNotesRequest;
use App\Modules\Medical\Http\Requests\UpdateCaseTitleRequest;
use App\Modules\Medical\Http\Resources\CaseListResource;
use App\Modules\Medical\Http\Resources\CaseShowResource;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Modules\Medical\Services\CaseService;
use App\Support\FilterHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function __construct(
        private CaseService $caseService,
        private CaseRepository $caseRepository,
        private DoctorDirectoryContract $doctorDirectory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CaseRecord::class);

        $paginator = $this->caseService->listForActiveClinic($request->user());

        $doctors = $request->user()->can('cases.viewAll')
            ? $this->doctorDirectory->activeForClinic()
                ->map(fn ($d) => ['id' => $d->id, 'display_name' => $d->display_name])
                ->values()
            : [];

        return Inertia::render('cases/Index', [
            'cases' => CaseListResource::collection($paginator),
            'doctors' => $doctors,
            'query' => FilterHelper::requestState([
                'status' => 'string',
                'doctor_id' => 'integer',
            ]),
            'ownDoctorId' => $request->user()->doctor?->id,
        ]);
    }

    public function store(StoreCaseRequest $request): RedirectResponse
    {
        $this->authorize('create', CaseRecord::class);

        $case = $this->caseService->createForPatient($request->validated(), $request->user());

        Toast::success(__('case.created'));

        return redirect()->route('cases.show', $case);
    }

    public function show(Request $request, CaseRecord $case): Response
    {
        $this->authorize('view', $case);

        $case->load([
            'patient:id,first_name,last_name',
            'doctor.user',
            'treatments' => fn ($q) => $q->with([
                'serviceLines' => fn ($sq) => $sq->orderBy('sort_order')->limit(1),
                'serviceLines.service',
            ])->orderByDesc('completed_at'),
        ]);

        // Ungrouped completed treatments for the same patient and doctor (for the link dialog).
        $ungroupedTreatments = Treatment::where('patient_id', $case->patient_id)
            ->where('doctor_id', $case->doctor_id)
            ->whereNull('case_id')
            ->where('status', TreatmentStatus::Completed->value)
            ->with([
                'doctor.user',
                'serviceLines' => fn ($q) => $q->orderBy('sort_order')->limit(1),
                'serviceLines.service',
            ])
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (Treatment $t) => [
                'id' => $t->id,
                'title' => $t->serviceLines->first()?->service?->name,
                'completed_at' => $t->completed_at?->toIso8601String(),
                'doctor_id' => (int) $t->doctor_id,
                'doctor_name' => $t->doctor->display_name,
                'total_amount' => (string) $t->total_amount,
            ])
            ->values();

        return Inertia::render('cases/Show', [
            'case' => (new CaseShowResource($case))->resolve(),
            'allowedTransitions' => $this->caseService->allowedTransitions($case),
            'ungroupedTreatments' => $ungroupedTreatments,
            'canEditTitle' => $this->caseService->canEditTitle($case),
            'ownDoctorId' => $request->user()->doctor?->id,
        ]);
    }

    public function changeStatus(ChangeCaseStatusRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validated();
        $to = CaseStatus::from($validated['status']);

        $this->caseService->changeStatus($case, $to, $request->user(), [
            'follow_up_date' => $validated['follow_up_date'] ?? null,
            'follow_up_note' => $validated['follow_up_note'] ?? null,
        ]);

        Toast::success(__('case.status_updated'));

        return redirect()->route('cases.show', $case);
    }

    public function updateNotes(UpdateCaseNotesRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $this->caseService->updateNotes($case, $request->validated()['notes'] ?? null);

        Toast::success(__('case.notes_updated'));

        return redirect()->route('cases.show', $case);
    }

    public function updateFollowUp(UpdateCaseFollowUpRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validated();
        $this->caseService->updateFollowUp(
            $case,
            $validated['follow_up_date'] ?? null,
            $validated['follow_up_note'] ?? null,
        );

        Toast::success(__('case.follow_up_updated'));

        return redirect()->route('cases.show', $case);
    }

    public function updateTitle(UpdateCaseTitleRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $this->caseService->updateTitle($case, $request->validated()['title']);

        Toast::success(__('case.title_updated'));

        return redirect()->route('cases.show', $case);
    }

    public function linkTreatments(LinkCaseTreatmentsRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $this->caseService->linkTreatments($case, $request->validated()['treatment_ids'], $request->user());

        Toast::success(__('case.treatments_linked'));

        return redirect()->route('cases.show', $case);
    }
}
