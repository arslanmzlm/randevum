<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Models\AppointmentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical appointment type shape shared by the index and edit pages.
 *
 * Page-level flags (canManage) are controller-level Inertia props — they do NOT live here.
 *
 * @mixin AppointmentType
 */
class AppointmentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'default_duration_minutes' => $this->default_duration_minutes,
            'is_active' => $this->is_active,
        ];
    }
}
