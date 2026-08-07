<?php

namespace App\Modules\Medical\Services;

use App\Enums\TreatmentStatus;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Medical\Contracts\TreatmentReaderContract;
use App\Modules\Medical\Repositories\TreatmentRepository;
use Illuminate\Support\Facades\Gate;

/**
 * Standalone read seam over the Treatment model. Kept separate from TreatmentService
 * (which depends on Billing's PaymentRecorderContract) so Billing can read treatment
 * data without forming a TreatmentService ↔ PaymentService dependency cycle.
 */
class TreatmentReader implements TreatmentReaderContract
{
    public function __construct(private TreatmentRepository $repository) {}

    public function completedTotalCap(int $treatmentId): ?string
    {
        // Row-locked: PaymentService checks this cap inside its own DB transaction so a
        // concurrent payment on the same treatment serializes here instead of both reading
        // the same stale paid total and both passing the cap. Locking outside an open
        // transaction is a harmless no-op.
        $treatment = $this->repository->lockForUpdate($treatmentId);

        if ($treatment === null || $treatment->status !== TreatmentStatus::Completed) {
            return null;
        }

        return $treatment->total_amount;
    }

    public function summaryForAppointment(int $appointmentId, User $user): ?array
    {
        $treatment = Treatment::with('details')
            ->where('appointment_id', $appointmentId)
            ->first();

        if ($treatment === null || ! Gate::forUser($user)->allows('view', $treatment)) {
            return null;
        }

        return [
            'id' => $treatment->id,
            'status' => $treatment->status->value,
            'complaint' => $treatment->details?->complaint,
            'diagnosis' => $treatment->details?->diagnosis,
            'total_amount' => $treatment->total_amount,
            'completed_at' => $treatment->completed_at?->toIso8601String(),
        ];
    }
}
