<?php

namespace App\Modules\Medical\Http\Requests\Concerns;

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait PatientFieldRules
{
    /**
     * Shared patient field rules for store/update. The phone unique rule differs only by
     * an ->ignore() on update, so the caller passes the prepared rule in.
     *
     * @return array<string, mixed>
     */
    protected function patientRules(Unique $phoneUnique): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'phone:TR', $phoneUnique],
            'contact_phone' => ['nullable', 'phone:TR'],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'occupation' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'notification_enabled' => ['boolean'],
            'is_legacy' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
