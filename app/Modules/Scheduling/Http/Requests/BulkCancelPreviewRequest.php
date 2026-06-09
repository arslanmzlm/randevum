<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkCancelPreviewRequest extends FormRequest
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
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'doctor_id' => [
                'nullable',
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $start = Carbon::createFromFormat('Y-m-d', $this->input('start_date'));
                $end = Carbon::createFromFormat('Y-m-d', $this->input('end_date'));

                if ($start && $end && $start->diffInDays($end) > 31) {
                    $validator->errors()->add('end_date', __('appointment_bulk_cancel.validation.max_span'));
                }
            },
        ];
    }
}
