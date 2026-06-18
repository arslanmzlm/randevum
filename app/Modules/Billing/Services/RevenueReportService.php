<?php

namespace App\Modules\Billing\Services;

use App\Enums\PaymentMethod;
use App\Models\Transaction;
use App\Modules\Billing\Repositories\TransactionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class RevenueReportService
{
    /** Above this span the period breakdown switches from daily to monthly buckets. */
    private const DAILY_GRANULARITY_MAX_DAYS = 92;

    public function __construct(
        private TransactionRepository $repository,
    ) {}

    /**
     * Revenue figures for the active clinic, all net-of-refunds. The summary cards
     * (today / this month) come from cheap SUM queries; the selected window is bucketed in
     * PHP against the clinic timezone (DB-agnostic) — daily for short spans, monthly for long
     * ones so the breakdown never explodes. Passing null for both dates means all-time.
     *
     * @return array{
     *   summary: array{today: string, this_month: string},
     *   range: array{
     *     start: string,
     *     end: string,
     *     entire: bool,
     *     granularity: 'day'|'month',
     *     total: string,
     *     by_method: array<int, array{method: string, total: string}>,
     *     by_period: array<int, array{period: string, total: string}>,
     *   },
     * }
     */
    public function build(string $timezone, ?string $startDate, ?string $endDate): array
    {
        $now = CarbonImmutable::now($timezone);

        $summary = [
            'today' => $this->repository->collectedBetween(
                $now->startOfDay()->utc(),
                $now->endOfDay()->utc(),
            ),
            'this_month' => $this->repository->collectedBetween(
                $now->startOfMonth()->utc(),
                $now->endOfMonth()->utc(),
            ),
        ];

        $entire = $startDate === null && $endDate === null;

        [$rows, $startLocal, $endLocal] = $entire
            ? $this->allTimeWindow($timezone, $now)
            : $this->rangedWindow($timezone, $startDate, $endDate);

        $granularity = $startLocal->startOfDay()->diffInDays($endLocal->endOfDay()) > self::DAILY_GRANULARITY_MAX_DAYS
            ? 'month'
            : 'day';

        [$total, $byMethod, $byPeriod] = $this->bucket($rows, $timezone, $granularity);

        return [
            'summary' => $summary,
            'range' => [
                'start' => $startLocal->format('Y-m-d'),
                'end' => $endLocal->format('Y-m-d'),
                'entire' => $entire,
                'granularity' => $granularity,
                'total' => $total,
                'by_method' => $this->orderedMethods($byMethod),
                'by_period' => $byPeriod,
            ],
        ];
    }

    /**
     * All-time feed plus the actual first/last settled day (falls back to "today" when the
     * clinic has no settled transactions yet).
     *
     * @return array{0: Collection<int, Transaction>, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function allTimeWindow(string $timezone, CarbonImmutable $now): array
    {
        $rows = $this->repository->allSettledRows();

        if ($rows->isEmpty()) {
            return [$rows, $now, $now];
        }

        return [
            $rows,
            CarbonImmutable::parse($rows->first()->paid_at)->setTimezone($timezone),
            CarbonImmutable::parse($rows->last()->paid_at)->setTimezone($timezone),
        ];
    }

    /**
     * Rows within an explicit clinic-local date range.
     *
     * @return array{0: Collection<int, Transaction>, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function rangedWindow(string $timezone, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($endDate, $timezone)->endOfDay();

        $rows = $this->repository->settledRowsBetween($start->utc(), $end->utc());

        return [$rows, $start, $end];
    }

    /**
     * Net total, per-method totals and per-period totals, all bucketed in PHP against the
     * clinic timezone. Period key is Y-m-d (daily) or Y-m (monthly).
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array{0: string, 1: array<string, string>, 2: array<int, array{period: string, total: string}>}
     */
    private function bucket(Collection $rows, string $timezone, string $granularity): array
    {
        $byPeriod = [];
        $byMethod = [];
        $total = '0.00';

        foreach ($rows as $row) {
            $amount = (string) $row->amount;
            $total = bcadd($total, $amount, 2);

            $local = CarbonImmutable::parse($row->paid_at)->setTimezone($timezone);
            $period = $local->format($granularity === 'month' ? 'Y-m' : 'Y-m-d');
            $byPeriod[$period] = bcadd($byPeriod[$period] ?? '0.00', $amount, 2);

            $method = $row->payment_method->value;
            $byMethod[$method] = bcadd($byMethod[$method] ?? '0.00', $amount, 2);
        }

        ksort($byPeriod);

        $periods = array_map(
            static fn (string $period, string $sum): array => ['period' => $period, 'total' => $sum],
            array_keys($byPeriod),
            array_values($byPeriod),
        );

        return [$total, $byMethod, $periods];
    }

    /**
     * Methods present in the window, ordered by the PaymentMethod enum's declared order
     * so the breakdown always reads consistently (cash, card, transfer, cheque).
     *
     * @param  array<string, string>  $byMethod
     * @return array<int, array{method: string, total: string}>
     */
    private function orderedMethods(array $byMethod): array
    {
        $ordered = [];

        foreach (PaymentMethod::cases() as $method) {
            if (isset($byMethod[$method->value])) {
                $ordered[] = ['method' => $method->value, 'total' => $byMethod[$method->value]];
            }
        }

        return $ordered;
    }
}
