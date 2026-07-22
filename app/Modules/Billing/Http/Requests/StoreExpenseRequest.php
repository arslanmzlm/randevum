<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Http\Requests\Concerns\ExpenseRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    use ExpenseRules;

    /**
     * Authorization is handled by the controller via $this->authorize('create', Expense::class).
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
        return $this->expenseRules();
    }
}
