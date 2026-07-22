<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Modules\Core\Support\Toast;
use App\Modules\Medical\Http\Requests\UpdateAnamnesisRequest;
use App\Modules\Medical\Services\AnamnesisReportService;
use App\Modules\Medical\Services\AnamnesisService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;

class AnamnesisController extends Controller
{
    public function __construct(
        private AnamnesisService $anamnesisService,
        private AnamnesisReportService $anamnesisReportService,
    ) {}

    /**
     * PUT /patients/{patient}/anamnesis
     */
    public function update(UpdateAnamnesisRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('updateAnamnesis', $patient);

        $this->anamnesisService->update($patient, $request->validated());

        Toast::success(__('health.saved'));

        return redirect()->back();
    }

    /**
     * GET /patients/{patient}/anamnesis/pdf
     * Streams a freshly rendered anamnesis PDF — reuses the patient `view` ability
     * (clinic-wide, no dedicated permission), same as the treatment report.
     */
    public function pdf(Patient $patient): Responsable
    {
        $this->authorize('view', $patient);

        return $this->anamnesisReportService->build($patient);
    }
}
