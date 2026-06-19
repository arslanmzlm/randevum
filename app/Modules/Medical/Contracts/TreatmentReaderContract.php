<?php

namespace App\Modules\Medical\Contracts;

/**
 * Read seam for treatment data used by the Billing module (overpayment guard).
 * Billing imports this contract; never the concrete TreatmentService or the Treatment model.
 */
interface TreatmentReaderContract
{
    /**
     * Overpayment cap for the given treatment: its total_amount (as a decimal string)
     * when the treatment is Completed, or null when there is no cap — the treatment is
     * a draft/prepayment (total not yet final) or it does not exist.
     */
    public function completedTotalCap(int $treatmentId): ?string;
}
