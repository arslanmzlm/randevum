<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\TreatmentStatus;
use App\Models\Patient;
use App\Models\Treatment;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TreatmentRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Treatment
    {
        return Treatment::create($data);
    }

    /**
     * Server-side paginated list of treatments for the active clinic, applying
     * request-driven search/filter/sort via FilterHelper.
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     *
     * @param  list<int>|null  $doctorIds  null = all doctors; non-null = constrain to these ids
     * @return LengthAwarePaginator<Treatment>
     */
    public function paginateForActiveClinic(?array $doctorIds, string $timezone): LengthAwarePaginator
    {
        $query = Treatment::query()
            ->with([
                // withTrashed(): a soft-deleted patient/doctor must still resolve here — the
                // treatment row outlives them and the list resource needs a name, not a null.
                'patient' => fn ($q) => $q->withTrashed()->select('id', 'first_name', 'last_name'),
                'doctor' => fn ($q) => $q->withTrashed()->with('user'),
                'serviceLines.service',
            ])
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds));

        $helper = FilterHelper::for($query)
            ->searchRelation('patient', ['first_name', 'last_name'], 'first_name', 'last_name', 'phone')
            ->enumMultiple(['status' => TreatmentStatus::class])
            ->multiple('doctor_id')
            ->multipleRelation('serviceLines', 'service_id')
            ->dateRange('created_at', 'start_date', 'end_date', $timezone);

        // id breaks ties: created_at alone is not a total order, so rows created in the same
        // second (seed/bulk day) can repeat or vanish across page boundaries.
        request()->filled('sort')
            ? $helper->sort('created_at', 'completed_at', 'total_amount', 'status')
            : $query->orderByDesc('created_at')->orderByDesc('id');

        return $helper->paginate();
    }

    /**
     * Sum of total_amount over each patient's Completed treatments, keyed by patient_id —
     * the "billed" side of the derived balance. Active-clinic scoped (ClinicScope); only the
     * given patients are queried.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, string> patient_id => billed total (decimal string)
     */
    public function billedTotalsForPatients(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        return Treatment::query()
            ->whereIn('patient_id', $patientIds)
            ->where('status', TreatmentStatus::Completed->value)
            ->groupBy('patient_id')
            ->selectRaw('patient_id, sum(total_amount) as total')
            ->pluck('total', 'patient_id')
            ->map(fn ($total): string => (string) $total)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Treatment $treatment, array $data): Treatment
    {
        $treatment->fill($data)->save();

        return $treatment;
    }

    /**
     * Find a treatment for the active clinic by id.
     * ClinicScope on Treatment isolates to the active clinic automatically.
     */
    public function find(int $treatmentId): ?Treatment
    {
        return Treatment::find($treatmentId);
    }

    /**
     * Ungrouped (case_id null) completed treatments for a patient + doctor, newest first.
     * Feeds the case Show "link treatments" dialog. ClinicScope isolates the tenant.
     *
     * @return Collection<int, Treatment>
     */
    public function ungroupedCompletedFor(int $patientId, int $doctorId): Collection
    {
        return Treatment::where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereNull('case_id')
            ->where('status', TreatmentStatus::Completed->value)
            ->with([
                'doctor.user',
                'serviceLines' => fn ($q) => $q->orderBy('sort_order')->limit(1),
                'serviceLines.service',
            ])
            ->orderByDesc('completed_at')
            ->get();
    }

    /**
     * Treatments for the patient's history panel, newest first.
     * Loads first service line for the title display; scope restricts to own doctor when needed.
     *
     * @return Collection<int, Treatment>
     */
    public function forPatient(Patient $patient, ?int $doctorId): Collection
    {
        return Treatment::where('patient_id', $patient->id)
            ->when($doctorId !== null, fn ($q) => $q->forDoctor($doctorId))
            ->with([
                'doctor.user',
                'case:id,title',
                'serviceLines' => fn ($q) => $q->orderBy('sort_order')->limit(1),
                'serviceLines.service',
            ])
            ->orderByDesc('created_at')
            ->get();
    }
}
