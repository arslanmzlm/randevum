<?php

namespace App\Modules\Core\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClinicRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $clinic).
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'lowercase',
                'alpha_dash',
                Rule::unique('clinics', 'slug')->ignore($clinicId),
            ],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'phone:TR'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'country_id' => ['required', Rule::exists('countries', 'id')],
            'city_id' => ['nullable', Rule::exists('cities', 'id')],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'default_slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'auto_no_show_enabled' => ['required', 'boolean'],
            'auto_no_show_grace_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'working_hours' => ['required', 'array', 'size:7'],
            'working_hours.monday' => ['required'],
            'working_hours.tuesday' => ['required'],
            'working_hours.wednesday' => ['required'],
            'working_hours.thursday' => ['required'],
            'working_hours.friday' => ['required'],
            'working_hours.saturday' => ['required'],
            'working_hours.sunday' => ['required'],
        ];
    }

    /**
     * Cross-field working_hours validation (closed XOR open/close; time ordering; break bounds).
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $days = $this->input('working_hours', []);

            if (! is_array($days)) {
                return;
            }

            $expected = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

            foreach ($expected as $day) {
                if (! isset($days[$day]) || ! is_array($days[$day])) {
                    continue; // already caught by `required` rules above
                }

                $this->validateDay($validator, $day, $days[$day]);
            }
        });
    }

    /**
     * @param  Validator  $validator
     * @param  array<string, mixed>  $day
     */
    private function validateDay($validator, string $dayKey, array $day): void
    {
        $field = "working_hours.{$dayKey}";

        // A day is either {closed:true} OR {open, close[, break]}
        $hasClosed = isset($day['closed']) && $day['closed'] === true;
        $hasOpen = isset($day['open']);
        $hasClose = isset($day['close']);

        if ($hasClosed) {
            // Closed days must not carry open/close/break
            if ($hasOpen || $hasClose || isset($day['break'])) {
                $validator->errors()->add($field, __('validation.working_hours_closed_conflict', ['attribute' => $field]));
            }

            return;
        }

        // Non-closed: open and close are required
        if (! $hasOpen || ! $hasClose) {
            $validator->errors()->add($field, __('validation.working_hours_open_close_required', ['attribute' => $field]));

            return;
        }

        if (! $this->isValidTime($day['open'])) {
            $validator->errors()->add($field, __('validation.working_hours_time_format', ['attribute' => "{$field}.open"]));

            return;
        }

        if (! $this->isValidTime($day['close'])) {
            $validator->errors()->add($field, __('validation.working_hours_time_format', ['attribute' => "{$field}.close"]));

            return;
        }

        if ($day['close'] <= $day['open']) {
            $validator->errors()->add($field, __('validation.working_hours_close_after_open', ['attribute' => $field]));

            return;
        }

        // Optional break
        if (isset($day['break']) && $day['break'] !== null) {
            $break = $day['break'];

            if (! is_array($break) || count($break) !== 2) {
                $validator->errors()->add($field, __('validation.working_hours_break_format', ['attribute' => "{$field}.break"]));

                return;
            }

            [$breakStart, $breakEnd] = $break;

            if (! $this->isValidTime($breakStart) || ! $this->isValidTime($breakEnd)) {
                $validator->errors()->add($field, __('validation.working_hours_time_format', ['attribute' => "{$field}.break"]));

                return;
            }

            if ($breakEnd <= $breakStart) {
                $validator->errors()->add($field, __('validation.working_hours_break_end_after_start', ['attribute' => "{$field}.break"]));

                return;
            }

            if ($breakStart < $day['open'] || $breakEnd > $day['close']) {
                $validator->errors()->add($field, __('validation.working_hours_break_within_hours', ['attribute' => "{$field}.break"]));
            }
        }
    }

    private function isValidTime(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /**
     * validation.attributes.name is the generic fallback shared by every entity form; the clinic
     * form wants its own label, still sourced from the lang file.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.clinic_name')];
    }
}
