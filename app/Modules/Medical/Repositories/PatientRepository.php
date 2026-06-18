<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\Gender;
use App\Enums\TreatmentStatus;
use App\Models\Patient;
use App\Models\Treatment;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PatientRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on Patient restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<Patient>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        // Correlated subquery: the patient's most recent completed-treatment time, surfaced
        // as a sortable "last visit" column. Treatment's ClinicScope keeps it tenant-safe.
        $lastVisit = Treatment::query()
            ->selectRaw('max(completed_at)')
            ->whereColumn('treatments.patient_id', 'patients.id')
            ->where('status', TreatmentStatus::Completed->value);

        $query = Patient::query()
            ->select('patients.*')
            ->addSelect(['last_visit_at' => $lastVisit]);

        return FilterHelper::for($query)
            ->search('first_name', 'last_name', 'phone')
            ->sort('first_name', 'last_name', 'created_at', 'last_visit_at')
            ->enum(['gender' => Gender::class])
            ->boolean('is_legacy')
            ->paginate();
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

        return Patient::query()
            ->where(function ($q) use ($term, $nationalDigits): void {
                // Match the fragment against either name part AND the joined
                // "first last" so a full-name query ("Mehmet Yılmaz") still hits.
                // whereLike on a raw concat keeps the driver-correct LIKE/ILIKE
                // mapping (raw ILIKE would break the sqlite test connection).
                $q->whereLike('first_name', "%{$term}%")
                    ->orWhereLike('last_name', "%{$term}%")
                    ->orWhereLike(DB::raw("first_name || ' ' || last_name"), "%{$term}%");

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
