<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentTypeRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $appointmentType).
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

    /**
     * validation.attributes.name is the generic fallback shared by every entity form; this form
     * wants its own label, still sourced from the lang file.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.appointment_type_name')];
    }
}
