<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical patient shape shared across index, show, and edit pages.
 *
 * Page-level flags (canManage) are controller-level Inertia props — not here.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->first_name.' '.$this->last_name,
            'phone' => $this->phone,
            'contact_phone' => $this->contact_phone,
            'email' => $this->email,
            'birth_date' => $this->birth_date?->toDateString(),
            'age' => $this->birth_date ? (int) Carbon::parse($this->birth_date)->age : null,
            'gender' => $this->gender?->value,
            'notification_enabled' => (bool) $this->notification_enabled,
            'is_legacy' => (bool) $this->is_legacy,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toISOString(),
            // Present only on the list query (a sortable subselect); null on show/edit.
            'last_visit_at' => $this->last_visit_at
                ? Carbon::parse($this->last_visit_at)->toISOString()
                : null,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values()),
        ];
    }
}
