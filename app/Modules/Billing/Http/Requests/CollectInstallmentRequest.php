<?php

namespace App\Modules\Billing\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectInstallmentRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Transaction::class).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * v1 is full-installment collection only — the amount is fixed to the installment's own
     * amount in the Service, never client-supplied.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
