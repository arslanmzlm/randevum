<?php

namespace App\Modules\Billing\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\ClinicContext;
use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Transaction::class).
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
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinicId),
            ],
            'treatment_id' => [
                'nullable',
                'integer',
                Rule::exists('treatments', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('patient_id', (int) $this->input('patient_id')),
            ],
            'amount' => ['required', ...ValidationRules::money(0.01)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
