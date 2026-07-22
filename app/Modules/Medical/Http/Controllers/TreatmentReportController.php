<?php

namespace App\Modules\Medical\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Treatment;
use App\Modules\Medical\Services\TreatmentReportService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;

class TreatmentReportController extends Controller
{
    public function __construct(
        private TreatmentReportService $treatmentReportService,
    ) {}

    /**
     * GET /treatments/{treatment}/report
     * Streams a freshly rendered treatment-summary PDF — same visibility as the Show
     * page (reuses the `view` ability, no dedicated permission).
     */
    public function show(Request $request, Treatment $treatment): Responsable
    {
        $this->authorize('view', $treatment);

        $showPayments = (bool) $request->user()?->can('transactions.viewAny');

        return $this->treatmentReportService->build($treatment, $showPayments);
    }
}
