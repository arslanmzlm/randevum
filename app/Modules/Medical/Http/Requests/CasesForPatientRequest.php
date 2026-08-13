<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CasesForPatientRequest extends FormRequest
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

        return [
            // Same gate as StoreCaseRequest: the clinic-scoped query behind this already
            // returns nothing for a foreign id, but a follow-up may only be opened against a
            // live patient of THIS clinic, so reject the id instead of answering with [].
            // whereNull('deleted_at'): Rule::exists builds a raw query, so SoftDeletes does
            // not apply.
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
