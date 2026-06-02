<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim patient shape for typeahead/autocomplete endpoints.
 * Only id, full_name, and phone — keeps per-keystroke payload lean.
 *
 * @mixin Patient
 */
class PatientSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'full_name' => $this->first_name.' '.$this->last_name,
            'phone' => $this->phone,
        ];
    }
}
