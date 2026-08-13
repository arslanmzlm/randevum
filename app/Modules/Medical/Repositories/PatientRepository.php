<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\Gender;
use App\Enums\TreatmentStatus;
use App\Models\Patient;
use App\Models\Treatment;
use App\Support\FilterHelper;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PatientRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on Patient restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<Patient>
     */
    public function paginateForActiveClinic(string $timezone): LengthAwarePaginator
    {
        // Correlated subquery: the patient's most recent completed-treatment time, surfaced
        // as a sortable "last visit" column. Treatment's ClinicScope keeps it tenant-safe.
        $lastVisit = Treatment::query()
            ->selectRaw('max(completed_at)')
            ->whereColumn('treatments.patient_id', 'patients.id')
            ->where('status', TreatmentStatus::Completed->value);

        $query = Patient::query()
            ->select('patients.*')
            ->addSelect(['last_visit_at' => $lastVisit])
            ->with('tags');

        // A search is a targeted lookup ("where did that record go?"), so it reaches soft-deleted
        // patients too — matching the treatment/appointment lists, which keep showing their rows.
        // The unsearched list stays clean (default scope), so deleted patients are not browsable.
        $term = request()->input('filter.search');

        if (is_string($term) && filled($term)) {
            $query->withTrashed();
        }

        $this->applyTagFilter($query);
        $this->applyLastVisitFilter($query, $timezone);

        return FilterHelper::for($query)
            ->search(['first_name', 'last_name'], 'first_name', 'last_name', 'phone')
            ->sort('first_name', 'last_name', 'created_at', 'last_visit_at')
            ->enum(['gender' => Gender::class])
            ->boolean('is_legacy')
            ->paginate();
    }

    /**
     * `filter[tags]` — comma-separated tag ids, OR-matched (patient holds ANY of them).
     * Non-numeric members are dropped; ClinicScope on `tags` keeps a foreign-clinic id
     * a no-op (it simply matches nothing) rather than a cross-tenant leak.
     *
     * @param  Builder<Patient>  $query
     */
    private function applyTagFilter(Builder $query): void
    {
        $raw = request()->input('filter.tags');

        if (blank($raw) || ! is_string($raw)) {
            return;
        }

        $ids = array_values(array_filter(array_map(
            static fn (string $id): ?int => is_numeric($id) ? (int) $id : null,
            explode(',', $raw),
        )));

        if ($ids === []) {
            return;
        }

        $query->whereHas('tags', fn (Builder $q) => $q->whereIn('tags.id', $ids));
    }

    /**
     * `filter[last_visit_after]` / `filter[last_visit_before]` (Y-m-d, clinic timezone) —
     * over the patient's most recent Completed treatment.
     *
     * `before` resolves to "hasn't visited since that date" (whereDoesntHave), which by
     * design ALSO matches patients who have never visited — the re-engagement "son X ayda
     * gelmeyen" wide net (GATE-1 OPEN-3, deliberate — not a bug).
     *
     * @param  Builder<Patient>  $query
     */
    private function applyLastVisitFilter(Builder $query, string $timezone): void
    {
        $after = rescue(fn () => request()->date('filter.last_visit_after', tz: $timezone), null, report: false);
        $before = rescue(fn () => request()->date('filter.last_visit_before', tz: $timezone), null, report: false);

        if ($after !== null) {
            // Bindings are sent as plain strings — normalize to UTC first so a clinic-local
            // day boundary compares correctly against the timestamptz column (mirrors
            // AppointmentRepository's day/week/month range helpers).
            $start = $after->startOfDay()->utc();

            $query->whereHas('treatments', function (Builder $q) use ($start): void {
                $q->where('status', TreatmentStatus::Completed->value)
                    ->where('completed_at', '>=', $start);
            });
        }

        if ($before !== null) {
            $end = $before->endOfDay()->utc();

            $query->whereDoesntHave('treatments', function (Builder $q) use ($end): void {
                $q->where('status', TreatmentStatus::Completed->value)
                    ->where('completed_at', '>=', $end);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Patient
    {
        return Patient::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data): Patient
    {
        $patient->fill($data)->save();

        return $patient;
    }

    public function delete(Patient $patient): void
    {
        $patient->delete();
    }

    public function restore(Patient $patient): void
    {
        $patient->restore();
    }

    /**
     * Capped name+phone typeahead lookup for the active clinic.
     *
     * Returns an empty collection when $term is shorter than 2 chars.
     * ClinicScope and SoftDeletes scopes apply automatically.
     * Phones are stored E.164 (+90…); callers strip non-digits and leading 0
     * so national digits form a substring match against the stored value.
     *
     * @return Collection<int, Patient>
     */
    public function search(string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $digits = preg_replace('/\D/', '', $term) ?? '';
        $nationalDigits = $digits !== '' ? ltrim($digits, '0') : '';

        $needle = '%'.SearchTerm::normalize($term).'%';

        return Patient::query()
            ->where(function ($q) use ($needle, $nationalDigits): void {
                // Match the fragment against either name part AND the joined "first last" so a
                // full-name query ("Mehmet Yılmaz") still hits. Both sides are folded to ASCII
                // so "ırmak"/"Irmak"/"irmak" find the same patient (see SearchTerm).
                $q->whereLike(SearchTerm::column('first_name'), $needle)
                    ->orWhereLike(SearchTerm::column('last_name'), $needle)
                    ->orWhereLike(SearchTerm::column(['first_name', 'last_name']), $needle);

                if ($nationalDigits !== '') {
                    $q->orWhereLike('phone', "%{$nationalDigits}%");
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(10)
            ->get();
    }

    /**
     * Find a soft-deleted patient by E.164 phone in the active clinic.
     * ClinicScope still applies to onlyTrashed() — clinic isolation is preserved.
     */
    public function findTrashedByPhone(string $phone): ?Patient
    {
        return Patient::onlyTrashed()->where('phone', $phone)->first();
    }
}
