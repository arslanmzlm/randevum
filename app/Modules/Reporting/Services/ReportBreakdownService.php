<?php

namespace App\Modules\Reporting\Services;

use App\Enums\ReportTab;
use App\Modules\Reporting\Repositories\ReportBreakdownRepository;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Collator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;

/**
 * Builds a single report-breakdown tab's rows: aggregate → hydrate labels → sort →
 * total → (optionally) paginate. Computed live every call — no cache, unlike the
 * finance tab's revenue cache (row counts are bounded by catalog cardinality:
 * doctors/services/products/appointment types/staff, never patient-scale).
 */
class ReportBreakdownService
{
    /** Sort fields every breakdown tab exposes. */
    private const SORT_FIELDS_COMMON = ['label', 'amount', 'count', 'average'];

    /** Extra sort fields the doctor tab exposes on top of the common set. */
    private const SORT_FIELDS_DOCTOR = ['appointment_count', 'cancelled_rate', 'no_show_rate'];

    /** Extra sort fields the branch (umbrella report) tab exposes on top of the common set. */
    private const SORT_FIELDS_BRANCH = ['expense', 'net'];

    public function __construct(
        private ReportBreakdownRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @param  list<int>  $branchIds  only consulted for ReportTab::Branch (the umbrella
     *                                report); every other tab ignores it.
     * @return array{
     *   data: array<int, array<string, mixed>>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int, from: ?int, to: ?int}|null,
     *   totals: array{amount: string, count: int},
     *   sort: string,
     * }
     */
    public function build(
        ReportTab $tab,
        string $timezone,
        ?string $startDate,
        ?string $endDate,
        string $sort,
        int $perPage,
        bool $paginate,
        array $branchIds = [],
    ): array {
        $startUtc = $this->moneyBound($startDate, $timezone, endOfDay: false);
        $endUtc = $this->moneyBound($endDate, $timezone, endOfDay: true);

        $rows = match ($tab) {
            ReportTab::Finance => throw new InvalidArgumentException('ReportBreakdownService does not handle the finance tab.'),
            ReportTab::Doctor => $this->doctorRows($startUtc, $endUtc),
            ReportTab::Service => $this->lineRows(
                $this->repository->serviceLineTotals($startUtc, $endUtc),
                fn (array $ids): array => $this->repository->serviceLabels($ids),
            ),
            ReportTab::Product => $this->lineRows(
                $this->repository->productLineTotals($startUtc, $endUtc),
                fn (array $ids): array => $this->repository->productLabels($ids),
            ),
            ReportTab::AppointmentType => $this->appointmentTypeRows($startUtc, $endUtc),
            ReportTab::ExpenseOwner => $this->expenseOwnerRows($startDate, $endDate),
            ReportTab::Branch => $this->branchRows($branchIds, $startUtc, $endUtc, $startDate, $endDate),
        };

        [$field, $direction] = $this->resolveSort($tab, $sort);
        $this->sortRows($rows, $field, $direction);
        $resolvedSort = ($direction === 'desc' ? '-' : '').$field;

        $totals = $this->totals($rows);

        if (! $paginate) {
            return ['data' => $rows, 'meta' => null, 'totals' => $totals, 'sort' => $resolvedSort];
        }

        $page = Paginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
        );

        return [
            'data' => array_values($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'totals' => $totals,
            'sort' => $resolvedSort,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doctorRows(?CarbonImmutable $startUtc, ?CarbonImmutable $endUtc): array
    {
        $collected = $this->repository->collectedByDoctor($startUtc, $endUtc);
        $completedCounts = $this->repository->completedTreatmentCountByDoctor($startUtc, $endUtc);
        $appointmentCounts = $this->repository->appointmentCountsByDoctor($startUtc, $endUtc);

        $ids = array_unique(array_merge(
            array_keys($collected),
            array_keys($completedCounts),
            array_keys($appointmentCounts),
        ));
        $labels = $this->repository->doctorLabels($ids);
        $deletedIds = $this->repository->deletedDoctorIds($ids);

        $rows = [];

        foreach ($ids as $id) {
            $amount = $collected[$id] ?? '0.00';
            $count = $completedCounts[$id] ?? 0;
            $appointments = $appointmentCounts[$id] ?? [
                'total' => 0, 'cancelled' => 0, 'no_show' => 0,
                'past_total' => 0, 'past_cancelled' => 0, 'past_no_show' => 0,
            ];

            $rows[] = [
                'id' => $id,
                'label' => $labels[$id] ?? __('report.unspecified'),
                // doctorLabels() reaches soft-deleted doctors on purpose (a past period must
                // still name whoever worked it), so the row has to say the profile is gone.
                'is_deleted' => in_array($id, $deletedIds, true),
                'amount' => $amount,
                'count' => $count,
                'average' => $this->average($amount, $count),
                'appointment_count' => $appointments['total'],
                'cancelled_count' => $appointments['cancelled'],
                'no_show_count' => $appointments['no_show'],
                // Rate numerator AND denominator are past-only: a future-dated cancellation
                // must not inflate the rate for a window that includes it (adet metrikleri
                // above stay whole-window, per Owner brief).
                'cancelled_rate' => $this->rate($appointments['past_cancelled'], $appointments['past_total']),
                'no_show_rate' => $this->rate($appointments['past_no_show'], $appointments['past_total']),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{quantity: int, total: string}>  $lineTotals
     * @param  callable(array<int, int>): array<int, string>  $labelResolver
     * @return array<int, array<string, mixed>>
     */
    private function lineRows(array $lineTotals, callable $labelResolver): array
    {
        $labels = $labelResolver(array_keys($lineTotals));

        $rows = [];

        foreach ($lineTotals as $id => $data) {
            $rows[] = [
                'id' => $id,
                'label' => $labels[$id] ?? __('report.unspecified'),
                'amount' => $data['total'],
                'count' => $data['quantity'],
                'average' => $this->average($data['total'], $data['quantity']),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function appointmentTypeRows(?CarbonImmutable $startUtc, ?CarbonImmutable $endUtc): array
    {
        $collected = $this->repository->collectedByAppointmentType($startUtc, $endUtc);
        $counts = $this->repository->appointmentCountsByType($startUtc, $endUtc);

        $keys = array_unique(array_merge(array_keys($collected), array_keys($counts)));
        $ids = array_values(array_filter(array_map(
            static fn (string $key): ?int => $key === '' ? null : (int) $key,
            $keys,
        )));
        $labels = $this->repository->appointmentTypeLabels($ids);

        $rows = [];

        foreach ($keys as $key) {
            $id = $key === '' ? null : (int) $key;
            $amount = $collected[$key] ?? '0.00';
            $count = $counts[$key] ?? 0;

            $rows[] = [
                'id' => $id,
                'label' => $id === null ? __('report.unspecified') : ($labels[$id] ?? __('report.unspecified')),
                'amount' => $amount,
                'count' => $count,
                'average' => $this->average($amount, $count),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expenseOwnerRows(?string $startDate, ?string $endDate): array
    {
        $totals = $this->repository->expenseTotalsByOwner($startDate, $endDate);

        $ids = array_values(array_filter(array_map(
            static fn (string $key): ?int => $key === '' ? null : (int) $key,
            array_keys($totals),
        )));
        $labels = $this->repository->userLabels($ids);

        $rows = [];

        foreach ($totals as $key => $data) {
            $id = $key === '' ? null : (int) $key;

            $rows[] = [
                'id' => $id,
                'label' => $id === null ? __('report.unspecified') : ($labels[$id] ?? __('report.unspecified')),
                'amount' => $data['total'],
                'count' => $data['count'],
                'average' => $this->average($data['total'], $data['count']),
            ];
        }

        return $rows;
    }

    /**
     * Umbrella (çatı) report rows: one row per tenant branch, "şube kırılımlı + toplam".
     *
     * @param  list<int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    private function branchRows(array $branchIds, ?CarbonImmutable $startUtc, ?CarbonImmutable $endUtc, ?string $startDate, ?string $endDate): array
    {
        $collected = $this->repository->collectedByClinic($branchIds, $startUtc, $endUtc);
        $settledCounts = $this->repository->settledCountByClinic($branchIds, $startUtc, $endUtc);
        $expenses = $this->repository->expenseTotalsByClinic($branchIds, $startDate, $endDate);
        $labels = $this->repository->clinicLabels($branchIds);

        $rows = [];

        foreach ($branchIds as $id) {
            $amount = $collected[$id] ?? '0.00';
            $count = $settledCounts[$id] ?? 0;
            $expense = $expenses[$id] ?? '0.00';

            $rows[] = [
                'id' => $id,
                'label' => $labels[$id] ?? __('report.unspecified'),
                'amount' => $amount,
                'count' => $count,
                'average' => $this->average($amount, $count),
                'expense' => $expense,
                'net' => bcsub($amount, $expense, 2),
            ];
        }

        return $rows;
    }

    private function average(string $amount, int $count): string
    {
        return $count > 0 ? bcdiv($amount, (string) $count, 2) : '0.00';
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    /**
     * Clinic-local date → UTC day boundary for the money-side (paid_at/completed_at)
     * queries. A null date (all-time) stays null.
     */
    private function moneyBound(?string $date, string $timezone, bool $endOfDay): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        $local = CarbonImmutable::parse($date, $timezone);

        return ($endOfDay ? $local->endOfDay() : $local->startOfDay())->utc();
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function resolveSort(ReportTab $tab, string $sort): array
    {
        $field = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $allowed = match ($tab) {
            ReportTab::Doctor => [...self::SORT_FIELDS_COMMON, ...self::SORT_FIELDS_DOCTOR],
            ReportTab::Branch => [...self::SORT_FIELDS_COMMON, ...self::SORT_FIELDS_BRANCH],
            default => self::SORT_FIELDS_COMMON,
        };

        if (! in_array($field, $allowed, true)) {
            return ['amount', 'desc'];
        }

        return [$field, $direction];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sortRows(array &$rows, string $field, string $direction): void
    {
        $collator = collator_create($this->clinicContext->locale());

        usort($rows, function (array $a, array $b) use ($field, $direction, $collator): int {
            $cmp = $this->compare($a, $b, $field, $collator);

            if ($cmp !== 0) {
                return $direction === 'desc' ? -$cmp : $cmp;
            }

            // The label tie-break only stabilizes equal rows, so it stays ascending in
            // both directions — negating it too would flip equal-amount rows to Z→A.
            return $field === 'label'
                ? 0
                : $this->compareLabels((string) $a['label'], (string) $b['label'], $collator);
        });
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b, string $field, ?Collator $collator): int
    {
        return match ($field) {
            'label' => $this->compareLabels((string) $a['label'], (string) $b['label'], $collator),
            'amount', 'average', 'expense', 'net' => bccomp((string) $a[$field], (string) $b[$field], 2),
            default => ($a[$field] ?? 0) <=> ($b[$field] ?? 0),
        };
    }

    /**
     * Locale-aware label comparison. strcoll() would honour LC_COLLATE, but Laravel never
     * calls setlocale(), so the process stays in "C" and Ç/Ğ/İ/Ö/Ş/Ü sort after Z.
     */
    private function compareLabels(string $a, string $b, ?Collator $collator): int
    {
        if ($collator instanceof Collator) {
            $result = $collator->compare($a, $b);

            if ($result !== false) {
                return $result;
            }
        }

        return strcasecmp($a, $b);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{amount: string, count: int}
     */
    private function totals(array $rows): array
    {
        $amount = '0.00';
        $count = 0;
        // Only the branch (umbrella) rows carry expense/net; the tenant-wide net is that
        // report's headline number, so the totals row has to carry it too.
        $hasExpense = $rows !== [] && array_key_exists('expense', $rows[0]);
        $expense = '0.00';
        $net = '0.00';

        foreach ($rows as $row) {
            $amount = bcadd($amount, (string) $row['amount'], 2);
            $count += (int) $row['count'];

            if ($hasExpense) {
                $expense = bcadd($expense, (string) $row['expense'], 2);
                $net = bcadd($net, (string) $row['net'], 2);
            }
        }

        return $hasExpense
            ? ['amount' => $amount, 'count' => $count, 'expense' => $expense, 'net' => $net]
            : ['amount' => $amount, 'count' => $count];
    }
}
