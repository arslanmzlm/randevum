<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Repositories\ExpenseReportRepository;

class ExpenseReportService
{
    public function __construct(
        private ExpenseReportRepository $repository,
    ) {}

    /**
     * Expense figures for the active clinic within the given expense_date window
     * (either bound may be omitted for all-time). expense_date is a plain date
     * column — no timezone math needed, unlike the revenue side. Cheap indexed
     * SUM/group-by on a small table, so this is computed live every request
     * (no cache), unlike RevenueReportService's 1-hour cache.
     *
     * @return array{total: string, by_category: array<int, array{category: ?string, total: string}>}
     */
    public function build(?string $startDate, ?string $endDate): array
    {
        $total = $this->repository->totalBetween($startDate, $endDate);
        $byCategory = $this->repository->byCategoryBetween($startDate, $endDate);

        arsort($byCategory);

        return [
            'total' => $total,
            'by_category' => $this->rows($byCategory),
        ];
    }

    /**
     * @param  array<string, string>  $byCategory
     * @return array<int, array{category: ?string, total: string}>
     */
    private function rows(array $byCategory): array
    {
        return array_map(
            // A null category groups under the '' key (PHP casts a null array key to '').
            static fn (string $category, string $total): array => [
                'category' => $category === '' ? null : $category,
                'total' => $total,
            ],
            array_keys($byCategory),
            array_values($byCategory),
        );
    }
}
