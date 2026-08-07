<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchClinicRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('switchTo', $clinic).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', Rule::exists('clinics', 'id')->whereNull('deleted_at')],
        ];
    }
}
