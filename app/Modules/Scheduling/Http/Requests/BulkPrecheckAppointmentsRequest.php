<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Read-only pre-check of a bulk-booking occurrence list. Mirrors the occurrence/doctor/service
 * rules of BulkStoreAppointmentsRequest (no patient block — the probe never touches a patient).
 */
class BulkPrecheckAppointmentsRequest extends FormRequest
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
        $clinicId = app(ClinicContext::class)->id();

        return [
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
            'occurrences' => ['required', 'array', 'min:1', 'max:12'],
            'occurrences.*' => ['required', 'array'],
            'occurrences.*.starts_at' => ['required', 'date'],
            'occurrences.*.duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'occurrences.*.appointment_type_id' => [
                'nullable',
                'integer',
                Rule::exists('appointment_types', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('is_active', true),
            ],
        ];
    }
}
