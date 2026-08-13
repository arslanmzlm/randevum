<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTrPhone;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    use NormalizesTrPhone;

    public function authorize(): bool
    {
        // Authorization is handled in the controller via policy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('new_patient.phone')) {
            try {
                $this->merge([
                    'new_patient' => [
                        ...$this->input('new_patient'),
                        'phone' => $this->toE164((string) $this->input('new_patient.phone')),
                    ],
                ]);
            } catch (\Exception) {
                // Leave as-is; the phone:TR rule rejects invalid numbers.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();

        return [
            'patient_mode' => ['required', Rule::in(['existing', 'new'])],
            'patient_id' => [
                'required_if:patient_mode,existing',
                'nullable',
                'integer',
                // whereNull('deleted_at'): Rule::exists builds a raw query, so SoftDeletes does
                // not apply — without it a soft-deleted patient's id books a new appointment.
                Rule::exists('patients', 'id')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
            'new_patient' => ['nullable', 'required_if:patient_mode,new', 'array'],
            'new_patient.first_name' => ['required_if:patient_mode,new', 'nullable', 'string', 'max:100'],
            'new_patient.last_name' => ['required_if:patient_mode,new', 'nullable', 'string', 'max:100'],
            'new_patient.phone' => [
                'nullable',
                'phone:TR',
                Rule::unique('patients', 'phone')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
            'new_patient.email' => ['nullable', 'email', 'max:255'],
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
            'appointment_type_id' => [
                'nullable',
                'integer',
                Rule::exists('appointment_types', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('is_active', true),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'is_walk_in' => ['boolean'],
        ];
    }

    /**
     * The new-patient fields are conditionally required (required_if:patient_mode,new), but its
     * default message leaks the internal patient_mode field — show a plain "field is required".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_patient.first_name.required_if' => __('validation.required'),
            'new_patient.last_name.required_if' => __('validation.required'),
        ];
    }
}
