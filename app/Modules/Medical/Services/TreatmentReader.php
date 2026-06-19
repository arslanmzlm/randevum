<?php

namespace App\Modules\Medical\Services;

use App\Enums\TreatmentStatus;
use App\Models\Treatment;
use App\Modules\Medical\Contracts\TreatmentReaderContract;

/**
 * Standalone read seam over the Treatment model. Kept separate from TreatmentService
 * (which depends on Billing's PaymentRecorderContract) so Billing can read treatment
 * data without forming a TreatmentService ↔ PaymentService dependency cycle.
 */
class TreatmentReader implements TreatmentReaderContract
{
    public function completedTotalCap(int $treatmentId): ?string
    {
        $treatment = Treatment::find($treatmentId);

        if ($treatment === null || $treatment->status !== TreatmentStatus::Completed) {
            return null;
        }

        return $treatment->total_amount;
    }
}
