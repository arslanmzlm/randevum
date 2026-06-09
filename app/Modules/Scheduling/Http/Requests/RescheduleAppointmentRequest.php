<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleAppointmentRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Without appointments.assignDoctor a doctor may only reschedule their own appointments.
            $user = $this->user();

            if (! $user->can('appointments.update') || $user->can('appointments.assignDoctor')) {
                return;
            }

            $ownDoctorId = $user->doctor?->id;

            if ($ownDoctorId === null || (int) $this->input('doctor_id') !== $ownDoctorId) {
                $validator->errors()->add('doctor_id', __('appointment.errors.doctor_not_allowed'));
            }
        });
    }
}
