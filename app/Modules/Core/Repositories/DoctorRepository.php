<?php

namespace App\Modules\Core\Repositories;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Collection;

class DoctorRepository
{
    /**
     * Ordered list of all clinic doctors with their linked user, for the index page.
     *
     * ClinicScope on Doctor already filters to the active clinic.
     *
     * @return Collection<int, Doctor>
     */
    public function forClinicList(): Collection
    {
        return Doctor::with(['user', 'media'])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Doctor
    {
        return Doctor::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Doctor $doctor, array $data): void
    {
        $doctor->fill($data)->save();
    }

    public function delete(Doctor $doctor): void
    {
        $doctor->delete();
    }

    /**
     * All active doctors in the active clinic with their user loaded.
     *
     * ClinicScope on Doctor already limits results to the active clinic.
     *
     * @return Collection<int, Doctor>
     */
    public function activeForClinic(): Collection
    {
        return Doctor::with('user')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Find a single clinic doctor by profile id (active or inactive).
     *
     * Returns null when the doctor does not exist in the active clinic.
     */
    public function findForClinic(int $doctorId): ?Doctor
    {
        return Doctor::with('user')
            ->where('id', $doctorId)
            ->first();
    }
}
