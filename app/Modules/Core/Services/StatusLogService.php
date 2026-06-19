<?php

namespace App\Modules\Core\Services;

use App\Models\StatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared service for recording state transitions to status_logs.
 * Importable by any module — lives in Core (shared kernel).
 */
class StatusLogService
{
    public function record(
        Model $loggable,
        ?string $from,
        string $to,
        ?User $by = null,
        ?string $reason = null,
    ): void {
        StatusLog::create([
            // A status log always belongs to the loggable's clinic — derive it so the
            // record is correct even outside a request clinic context (queue/scheduler).
            'clinic_id' => $loggable->getAttribute('clinic_id'),
            'loggable_type' => $loggable->getMorphClass(),
            'loggable_id' => $loggable->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'transitioned_at' => now(),
            'by_user_id' => $by?->id,
            'reason' => $reason,
        ]);
    }
}
