<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Support\ClinicContext;
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
        AppointmentStatus::NoShow->value,
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

        // `doctor_id` arrives as a comma-joined id list (`?doctor_id=3,7`); explode it onto
        // the same key so the `array`/`integer` rules below validate each member.
        $doctorId = $this->input('doctor_id');

        if (is_string($doctorId)) {
            $this->merge(['doctor_id' => array_values(array_filter(
                array_map(trim(...), explode(',', $doctorId)),
                static fn (string $id): bool => $id !== '',
            ))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicContext = app(ClinicContext::class);
        $clinicId = $clinicContext->id();
        $activeClinic = $clinicContext->clinic();
        // Tenant-intersected, not the raw membership set: a user with clinic-scoped
        // roles at an unrelated tenant must not be able to pull that tenant's data
        // into this clinic's calendar via clinic_id[]. No active clinic (e.g. a user
        // with no clinic role at all) → no membership ids; the controller's
        // authorize() still 403s before this would otherwise matter.
        $membershipIds = $activeClinic === null
            ? []
            : app(ClinicMembershipService::class)->branchIdsForTenant($this->user(), $activeClinic->tenant_id);

        return [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            // Mirrors clinic_id's max:20 below — bounded by a clinic's realistic doctor headcount.
            'doctor_id' => ['nullable', 'array', 'max:20'],
            'doctor_id.*' => [
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(self::MVP_STATUSES)],
            // A foreign clinic id is rejected here (fail closed) rather than silently
            // dropped — every value must be one of the user's own memberships.
            'clinic_id' => ['sometimes', 'array', 'max:20'],
            'clinic_id.*' => ['integer', Rule::in($membershipIds)],
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
