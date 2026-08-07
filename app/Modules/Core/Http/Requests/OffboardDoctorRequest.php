<?php

namespace App\Modules\Core\Http\Requests;

use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class OffboardDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the controller via policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cancel_appointments' => ['boolean'],
            'reason' => ['nullable', ...ValidationRules::reason()],
        ];
    }
}
