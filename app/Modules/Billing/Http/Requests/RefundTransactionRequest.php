<?php

namespace App\Modules\Billing\Http\Requests;

use App\Models\Transaction;
use App\Support\ValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RefundTransactionRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via authorize('refund', $transaction).
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
            'amount' => ['required', ...ValidationRules::money(0.01)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                /** @var Transaction|null $transaction */
                $transaction = $this->route('transaction');

                if (! $transaction) {
                    return;
                }

                // Quick sanity check: amount cannot exceed the original payment amount.
                // The service re-guards the tighter remaining-cap check.
                if (bccomp((string) $this->input('amount'), (string) $transaction->amount, 2) > 0) {
                    $validator->errors()->add('amount', __('transactions.errors.amount_exceeds_remaining'));
                }
            },
        ];
    }
}
