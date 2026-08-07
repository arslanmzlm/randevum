<?php

namespace App\Modules\Medical\Contracts;

use App\Models\User;

/**
 * Read seam for treatment data used by the Billing and Scheduling modules.
 * Callers import this contract; never the concrete TreatmentService or the Treatment model.
 */
interface TreatmentReaderContract
{
    /**
     * Overpayment cap for the given treatment: its total_amount (as a decimal string)
     * when the treatment is Completed, or null when there is no cap — the treatment is
     * a draft/prepayment (total not yet final) or it does not exist.
     *
     * Locks the treatment row for the caller's open DB transaction — call this from inside
     * the same transaction that checks the cap and records the payment, so a concurrent
     * payment on the same treatment serializes instead of both passing a stale check.
     */
    public function completedTotalCap(int $treatmentId): ?string;

    /**
     * The appointment's 1:1 treatment as a display summary, or null when there is none or the
     * user may not view it (TreatmentPolicy::view). Medical owns the gate — callers never
     * resolve the Treatment model themselves.
     *
     * @return array{id:int,status:string,complaint:?string,diagnosis:?string,total_amount:string,completed_at:?string}|null
     */
    public function summaryForAppointment(int $appointmentId, User $user): ?array;
}
