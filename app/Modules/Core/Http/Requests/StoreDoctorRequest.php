<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreDoctorRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Doctor::class).
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'unique:users'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'title' => ['nullable', 'string', 'max:50'],
            'specialization' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'certificate' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
