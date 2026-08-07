<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\CaseStatus;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeCaseStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled in the controller via authorize().
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();
        $isFollowUp = fn () => $this->input('status') === CaseStatus::FollowUp->value;

        return [
            'status' => ['required', Rule::enum(CaseStatus::class)],
            'due_date' => ['nullable', 'date', Rule::requiredIf($isFollowUp)],
            'note' => ['nullable', 'string', 'max:1000'],
            'follow_up_type_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($isFollowUp),
                Rule::exists('follow_up_types', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
