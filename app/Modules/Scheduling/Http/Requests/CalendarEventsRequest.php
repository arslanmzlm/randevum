<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Enums\AppointmentStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarEventsRequest extends FormRequest
{
    /** MVP statuses available on the calendar (excludes Pending / NoShow — Faz 2). */
    private const MVP_STATUSES = [
        AppointmentStatus::Confirmed->value,
        AppointmentStatus::Rescheduled->value,
        AppointmentStatus::Arrived->value,
        AppointmentStatus::Completed->value,
        AppointmentStatus::Cancelled->value,
    ];

    /** Statuses returned when the caller omits the filter (Cancelled hidden by default). */
    private const DEFAULT_STATUSES = [
        AppointmentStatus::Confirmed->value,
        AppointmentStatus::Rescheduled->value,
        AppointmentStatus::Arrived->value,
        AppointmentStatus::Completed->value,
    ];

    public function authorize(): bool
    {
        // Authorization is handled in the controller via policy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Missing or empty statuses → default to all MVP statuses except Cancelled.
        if (empty($this->input('statuses'))) {
            $this->merge(['statuses' => self::DEFAULT_STATUSES]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(self::MVP_STATUSES)],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start', 'end'])) {
                    return;
                }

                $start = Carbon::createFromFormat('Y-m-d', $this->input('start'));
                $end = Carbon::createFromFormat('Y-m-d', $this->input('end'));

                if ($start->diffInDays($end) > 45) {
                    $validator->errors()->add('end', __('calendar.errors.range_exceeded'));
                }
            },
        ];
    }
}
