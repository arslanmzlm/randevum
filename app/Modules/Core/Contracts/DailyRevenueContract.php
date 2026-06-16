<?php

namespace App\Modules\Core\Contracts;

/**
 * Read seam that lets Scheduling compute today's revenue tile without importing
 * Billing concretely (module boundary: Scheduling must not query Transaction directly).
 * DailyRevenueService (Billing) implements it; binding lives in BillingServiceProvider.
 */
interface DailyRevenueContract
{
    /**
     * Net amount collected today in the active clinic (SUM of all transactions whose
     * paid_at falls within the clinic-local calendar day, including negative refund
     * counter-entries so the result is net-of-refunds).
     *
     * Returns a 2-dp decimal string (e.g. "1250.00"). BelongsToClinic global scope
     * provides tenant isolation — no explicit clinic_id filter needed.
     */
    public function collectedTodayForActiveClinic(string $timezone): string;
}
