<?php

namespace App\Modules\Billing\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the collections screen's `filter[patient_id]`, the one filter that names another
 * clinic's row space. The list is a GET with no form, so the rest of the filter bag stays
 * unvalidated the way every other list screen leaves it (FilterHelper already drops anything
 * it can't cast); only the patient id gets an existence gate, so a stale or foreign id is
 * rejected instead of silently rendering an empty list.
 */
class PaymentPlanIndexRequest extends FormRequest
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
            // No whereNull('deleted_at') here (unlike StoreCaseRequest): a plan outlives its
            // patient and the screen deliberately keeps listing it flagged deleted, so a
            // soft-deleted patient's id is a valid filter.
            'filter.patient_id' => [
                'nullable',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinicId),
            ],
        ];
    }
}
