<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Resolves the "clinic-local date range with an all-time toggle" filter shared by
 * the finance report and expense list pages — was inline in RevenueController,
 * extracted so ExpenseController can reuse it identically.
 */
class DateRangeFilter
{
    /**
     * @return array{0: bool, 1: ?string, 2: ?string} [entire, startDate, endDate]
     */
    public static function resolve(Request $request, string $timezone): array
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'entire' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('entire')) {
            return [true, null, null];
        }

        $now = CarbonImmutable::now($timezone);

        return [
            false,
            $validated['start'] ?? $now->startOfMonth()->format('Y-m-d'),
            $validated['end'] ?? $now->endOfMonth()->format('Y-m-d'),
        ];
    }

    /**
     * Normalizes the expense-list `category` query param: a trimmed value, or null
     * when blank. Shared by the finance and Giderlerim controllers.
     */
    public static function category(Request $request): ?string
    {
        $category = trim((string) $request->string('category'));

        return $category === '' ? null : $category;
    }
}
