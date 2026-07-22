<?php

namespace App\Modules\Billing\Http\Requests\Concerns;

use App\Support\ValidationRules;

/**
 * Shared rule fragment so store and update validate the same expense columns identically.
 */
trait ExpenseRules
{
    /**
     * @return array<string, mixed>
     */
    protected function expenseRules(): array
    {
        return [
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', ...ValidationRules::money(0.01)],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
