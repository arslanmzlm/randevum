<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Models\ScheduleException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduleException
 *
 * Requires `doctor.user` and `creator` to be eager-loaded before wrapping.
 */
class ScheduleExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->doctor?->display_name ?? '',
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'is_all_day' => $this->is_all_day,
            'reason' => $this->reason,
            'created_by_name' => $this->creator?->name,
            'can_delete' => ($this->doctor && $this->doctor->user_id === $user?->id)
                || (bool) $user?->can('scheduleExceptions.manage'),
        ];
    }
}
