<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentTypeRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', AppointmentType::class).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalize color: ensure leading '#' and uppercase hex digits.
        if ($this->filled('color')) {
            $color = ltrim((string) $this->input('color'), '#');
            $this->merge(['color' => '#'.strtoupper($color)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'default_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'is_active' => ['boolean'],
        ];
    }
}
