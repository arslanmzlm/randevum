<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Modules\Billing\Services\RevenueReportService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RevenueController extends Controller
{
    /** Report cache lifetime — a full-history scan runs at most once an hour per clinic. */
    private const CACHE_TTL_MINUTES = 60;

    public function __construct(
        private RevenueReportService $reportService,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('reports.revenue');

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        [$entire, $start, $end] = $this->resolveFilters($request, $clinic->timezone);

        $report = Cache::tags($this->cacheTag($clinic->id))->remember(
            $this->cacheKey($clinic->id, $entire, $start, $end),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->reportService->build(
                $clinic->timezone,
                $entire ? null : $start,
                $entire ? null : $end,
            ),
        );

        return Inertia::render('reports/Revenue', [
            'summary' => $report['summary'],
            'range' => $report['range'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire],
        ]);
    }

    /**
     * Drop every cached window for the active clinic so the next view recomputes fresh.
     * Flushing the whole tag (not just the current key) means a stale figure clears no matter
     * which range the user is on.
     */
    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorize('reports.revenue');

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        Cache::tags($this->cacheTag($clinic->id))->flush();

        Toast::success(__('revenue.cache_cleared'));

        return back();
    }

    /**
     * @return array{0: bool, 1: ?string, 2: ?string} [entire, startDate, endDate]
     */
    private function resolveFilters(Request $request, string $timezone): array
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

    private function cacheTag(int $clinicId): string
    {
        return "revenue-report:{$clinicId}";
    }

    private function cacheKey(int $clinicId, bool $entire, ?string $start, ?string $end): string
    {
        return "revenue-report:{$clinicId}:".($entire ? 'all' : "{$start}:{$end}");
    }
}
