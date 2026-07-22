<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\Gender;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSegmentRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', PatientSegment::class).
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
        $clinicId = app(ClinicContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:100'],
            'criteria' => ['required', 'array'],
            // Criteria dimensions are limited to queryable Medical-owned data (gender,
            // is_legacy, tags, last-visit range) — balance is deferred, cross-module.
            'criteria.gender' => ['nullable', Rule::enum(Gender::class)],
            'criteria.is_legacy' => ['nullable', 'boolean'],
            'criteria.tags' => ['nullable', 'array'],
            'criteria.tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('clinic_id', $clinicId),
            ],
            'criteria.last_visit_after' => ['nullable', 'date'],
            'criteria.last_visit_before' => ['nullable', 'date'],
        ];
    }
}
