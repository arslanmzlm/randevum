<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\Gender;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class StorePatientRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Patient::class).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhone('phone');
        $this->normalizePhone('contact_phone');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => [
                'nullable',
                'phone:TR',
                Rule::unique('patients', 'phone')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
            'contact_phone' => ['nullable', 'phone:TR'],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'notification_enabled' => ['boolean'],
            'is_legacy' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function normalizePhone(string $field): void
    {
        if ($this->filled($field)) {
            try {
                $this->merge([$field => (new PhoneNumber($this->input($field), 'TR'))->formatE164()]);
            } catch (\Exception) {
                // Leave as-is; the phone:TR validation rule will reject invalid numbers.
            }
        }
    }
}
