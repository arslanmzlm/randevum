<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\Gender;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            // The 'none' sentinel lets a segment capture the "Belirtilmemiş" (unspecified
            // gender) filter the list UI now offers.
            'criteria.gender' => ['nullable', 'string', Rule::in([...array_column(Gender::cases(), 'value'), FilterHelper::NONE])],
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

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $clinicId = app(ClinicContext::class)->id();

                // Case-insensitive per-clinic uniqueness — mirrors the DB-level functional
                // index (tags/follow_up_types precedent).
                $taken = DB::table('patient_segments')
                    ->where('clinic_id', $clinicId)
                    ->whereRaw('lower(name) = ?', [Str::lower($this->string('name')->value())])
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', __('validation.segment_name_taken'));
                }
            },
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
        return ['name' => __('validation.attributes.segment_name')];
    }
}
