<?php

namespace App\Modules\Billing\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualIncomeRequest extends FormRequest
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
        return [
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'amount' => ['required', ...ValidationRules::money(0.01)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'category' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
