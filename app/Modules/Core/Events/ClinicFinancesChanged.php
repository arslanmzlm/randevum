<?php

namespace App\Modules\Core\Events;

use Illuminate\Support\Facades\DB;

/**
 * A clinic's money figures moved: a payment, refund, manual income, expense or a
 * treatment leaving/entering the revenue scope. Fire-and-forget — the dispatcher wants
 * no answer, so this is a domain event rather than a cross-module contract.
 *
 * Lives in Core, not in the module that dispatches it: the consumer is Reporting, and
 * the arch rule pins Reporting to Core + sibling Contracts/* only (ArchTest, "module
 * Reporting joins tables, not sibling module code"), so a Billing/Medical-owned event
 * class would be unreachable from its own listener.
 */
class ClinicFinancesChanged
{
    public function __construct(public readonly int $clinicId) {}

    /**
     * Dispatch once the surrounding DB transaction commits. Firing mid-transaction would
     * let a concurrent read repopulate the cache from pre-commit data — the flush would
     * then be undone by the very write that triggered it. Outside a transaction this
     * fires immediately.
     */
    public static function dispatchAfterCommit(?int $clinicId): void
    {
        if ($clinicId === null) {
            return;
        }

        DB::afterCommit(fn () => event(new self($clinicId)));
    }
}
