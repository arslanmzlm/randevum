<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\CaseStatus;
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
        return [
            'status' => ['required', Rule::enum(CaseStatus::class)],
            'follow_up_date' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => $this->input('status') === CaseStatus::FollowUp->value),
            ],
            'follow_up_note' => ['nullable', 'string'],
        ];
    }
}
