<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical service shape shared by the index and edit pages.
 *
 * Page-level flags (canManage) are controller-level Inertia props — they do NOT live here.
 *
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'duration_minutes' => $this->duration_minutes,
            'default_complaint' => $this->default_complaint,
            'default_diagnosis' => $this->default_diagnosis,
            'default_treatment_process' => $this->default_treatment_process,
            'is_active' => $this->is_active,
        ];
    }
}
