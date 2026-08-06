<?php

namespace App\Modules\Core\Repositories;

use App\Models\StatusLog;
use Illuminate\Database\Eloquent\Collection;

class StatusLogRepository
{
    /**
     * A loggable's full status history, oldest → newest. ClinicScope (via BelongsToClinic
     * on StatusLog) isolates the tenant automatically.
     *
     * @return Collection<int, StatusLog>
     */
    public function forLoggable(string $loggableType, int $loggableId): Collection
    {
        return StatusLog::where('loggable_type', $loggableType)
            ->where('loggable_id', $loggableId)
            ->with('byUser:id,first_name,last_name') // `name` is an accessor — select the raw columns
            ->orderBy('transitioned_at')
            ->orderBy('id') // stable order for same-second transitions
            ->get();
    }
}
