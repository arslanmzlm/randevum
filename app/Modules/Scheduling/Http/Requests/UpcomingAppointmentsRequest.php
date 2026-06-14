<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpcomingAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the controller via policy.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->missing('limit') || $this->input('limit') === null) {
            $this->merge(['limit' => config('platform.appointment.upcoming_widget_limit')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
