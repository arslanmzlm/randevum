<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FollowUp;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\CasesForPatientRequest;
use App\Modules\Medical\Http\Requests\CompleteFollowUpRequest;
use App\Modules\Medical\Http\Requests\StoreFollowUpRequest;
use App\Modules\Medical\Services\CaseService;
use App\Modules\Medical\Services\FollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    public function __construct(
        private FollowUpService $followUpService,
        private CaseService $caseService,
    ) {}

    public function store(StoreFollowUpRequest $request): RedirectResponse
    {
        $this->authorize('create', FollowUp::class);

        $this->followUpService->create($request->validated(), $request->user());

        Toast::success(__('follow_up.created'));

        return back();
    }

    public function complete(CompleteFollowUpRequest $request, FollowUp $followUp): RedirectResponse
    {
        $this->authorize('complete', $followUp);

        $this->followUpService->complete($followUp, $request->validated()['result_note'] ?? null, $request->user());

        Toast::success(__('follow_up.completed'));

        return back();
    }

    public function cancel(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->authorize('complete', $followUp);

        $this->followUpService->cancel($followUp, $request->user());

        Toast::success(__('follow_up.cancelled'));

        return back();
    }

    /**
     * Cases for the picked patient, for the manual create-follow-up dialog's case select.
     * Doctors are narrowed to their own cases; anyone else who may create a follow-up sees
     * the patient's full case list (same behaviour as PatientController::show).
     */
    public function casesForPatient(CasesForPatientRequest $request): JsonResponse
    {
        $this->authorize('create', FollowUp::class);

        $cases = $this->caseService->casesForPatient((int) $request->validated('patient_id'), $request->user());

        return response()->json([
            'data' => array_map(
                fn (array $case) => ['id' => $case['id'], 'title' => $case['title'], 'status' => $case['status']],
                $cases,
            ),
        ]);
    }
}
