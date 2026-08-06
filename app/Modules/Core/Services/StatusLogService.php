<?php

namespace App\Modules\Core\Services;

use App\Models\StatusLog;
use App\Models\User;
use App\Modules\Core\Repositories\StatusLogRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared service for recording state transitions to status_logs.
 * Importable by any module — lives in Core (shared kernel).
 */
class StatusLogService
{
    public function __construct(private StatusLogRepository $repository) {}

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

    /**
     * A loggable's full status history, oldest → newest, mapped to a display-ready shape.
     * `by_user_name` is null for system/cron transitions (by_user_id null) — the caller
     * renders that as "otomatik".
     *
     * @return list<array{id:int,from_status:?string,to_status:string,transitioned_at:string,by_user_name:?string,reason:?string}>
     */
    public function historyFor(string $loggableType, int $loggableId): array
    {
        return $this->repository->forLoggable($loggableType, $loggableId)
            ->map(fn (StatusLog $log): array => [
                'id' => $log->id,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'transitioned_at' => $log->transitioned_at->toIso8601String(),
                'by_user_name' => $log->byUser?->name,
                'reason' => $log->reason,
            ])
            ->all();
    }
}
