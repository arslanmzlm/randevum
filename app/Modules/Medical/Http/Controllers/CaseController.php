<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Enums\CaseStatus;
use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\Treatment;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\ChangeCaseStatusRequest;
use App\Modules\Medical\Http\Requests\LinkCaseTreatmentsRequest;
use App\Modules\Medical\Http\Requests\StoreCaseRequest;
use App\Modules\Medical\Http\Requests\UpdateCaseNotesRequest;
use App\Modules\Medical\Http\Requests\UpdateCaseTitleRequest;
use App\Modules\Medical\Http\Resources\CaseListResource;
use App\Modules\Medical\Http\Resources\CaseShowResource;
use App\Modules\Medical\Services\CaseService;
use App\Modules\Medical\Services\FollowUpService;
use App\Modules\Medical\Services\FollowUpTypeService;
use App\Modules\Medical\Support\MediaItemMapper;
use App\Support\FilterHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function __construct(
        private CaseService $caseService,
        private DoctorDirectoryContract $doctorDirectory,
        private FollowUpService $followUpService,
        private FollowUpTypeService $followUpTypeService,
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
                // Vertical detail row (complaint/diagnosis/process) shown on the case page.
                'details',
                'media',
            ])->orderByDesc('completed_at'),
        ]);

        $data = (new CaseShowResource($case))->resolve();
        $data['follow_ups'] = $this->followUpService->forCase($case);

        // Read-only rollup across the case's treatments — KVKK-min, doctor + assistant
        // only. No upload/delete affordance here (upload only on treatment Process).
        $user = $request->user();

        if ($user->can('treatments.media.view')) {
            $data['media'] = $case->treatments
                // Per-treatment authorization, not the bare permission: a user may hold
                // treatments.media.view + cases.viewAll without treatments.viewAll, so
                // filter each treatment through the viewMedia policy (viewAll || owns).
                ->filter(fn ($treatment) => $user->can('viewMedia', $treatment))
                ->flatMap(fn ($treatment) => $treatment->getMedia('treatment_media')->map(
                    fn ($media) => MediaItemMapper::map($treatment, $media) + [
                        'treatment_id' => $treatment->id,
                        'treatment_date' => $treatment->completed_at?->toIso8601String(),
                    ]
                ))
                ->values()
                ->all();
        }

        return Inertia::render('cases/Show', [
            'case' => $data,
            'allowedTransitions' => $this->caseService->allowedTransitions($case),
            'ungroupedTreatments' => $this->caseService->ungroupedTreatmentsForCase($case),
            'canEditTitle' => $this->caseService->canEditTitle($case),
            // Mirrors CasePolicy::update exactly (viewAll, or ownership) — same gate the
            // mutating endpoints (changeStatus/updateNotes/linkTreatments/...) enforce.
            'canManage' => $user->can('update', $case),
            'canCompleteFollowUp' => $this->caseService->canCompleteFollowUp($case, $user),
            'followUpTypes' => $this->followUpTypeService->listActiveOptions(),
        ]);
    }

    public function changeStatus(ChangeCaseStatusRequest $request, CaseRecord $case): RedirectResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validated();
        $to = CaseStatus::from($validated['status']);

        $this->caseService->changeStatus($case, $to, $request->user(), [
            'due_date' => $validated['due_date'] ?? null,
            'note' => $validated['note'] ?? null,
            'follow_up_type_id' => $validated['follow_up_type_id'] ?? null,
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

    public function unlinkTreatment(CaseRecord $case, Treatment $treatment): RedirectResponse
    {
        $this->authorize('update', $case);

        $this->caseService->unlinkTreatment($case, $treatment);

        Toast::success(__('case.treatment_unlinked'));

        return back();
    }
}
