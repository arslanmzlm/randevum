<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Composes the revenue and expense reports into the unified finance-page figures
 * (net = revenue − expense), keeping ReportController thin. Owns the revenue
 * report's cache boundary (1h TTL, unchanged); the expense report is always
 * computed live (see ExpenseReportService), so a stale-revenue + fresh-expense
 * net is possible for up to an hour — acceptable, matches prior revenue staleness.
 */
class FinanceReportService
{
    /** Report cache lifetime — a full-history revenue scan runs at most once an hour per clinic. */
    private const CACHE_TTL_MINUTES = 60;

    public function __construct(
        private RevenueReportService $revenueReportService,
        private ExpenseReportService $expenseReportService,
    ) {}

    /**
     * @return array{
     *   revenue: array{
     *     summary: array{today: string, this_month: string},
     *     range: array<string, mixed>,
     *   },
     *   expense: array{total: string, by_category: array<int, array{category: ?string, total: string}>},
     *   net: string,
     * }
     */
    public function build(int $clinicId, string $timezone, ?string $startDate, ?string $endDate): array
    {
        $revenue = $this->cachedRevenue($clinicId, $timezone, $startDate, $endDate);
        $expense = $this->expenseReportService->build($startDate, $endDate);

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'net' => bcsub($revenue['range']['total'], $expense['total'], 2),
        ];
    }

    /**
     * Drop every cached revenue window for the clinic so the next view recomputes fresh.
     */
    public function clearCache(int $clinicId): void
    {
        Cache::tags($this->cacheTag($clinicId))->flush();
    }

    /**
     * @return array{summary: array{today: string, this_month: string}, range: array<string, mixed>}
     */
    private function cachedRevenue(int $clinicId, string $timezone, ?string $startDate, ?string $endDate): array
    {
        $entire = $startDate === null && $endDate === null;

        return Cache::tags($this->cacheTag($clinicId))->remember(
            $this->cacheKey($clinicId, $entire, $startDate, $endDate),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->revenueReportService->build($timezone, $startDate, $endDate),
        );
    }

    /**
     * Prefix bumped to v2: the cached array shape changed (patient/manual split), so a stale v1
     * entry would be missing the new keys. Bumping the tag drops old entries too — no manual flush.
     */
    private function cacheTag(int $clinicId): string
    {
        return "revenue-report:v2:{$clinicId}";
    }

    private function cacheKey(int $clinicId, bool $entire, ?string $startDate, ?string $endDate): string
    {
        return "revenue-report:v2:{$clinicId}:".($entire ? 'all' : "{$startDate}:{$endDate}");
    }
}
