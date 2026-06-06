<?php

namespace App\Modules\Scheduling\Repositories;

use App\Models\AppointmentType;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class AppointmentTypeRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on AppointmentType restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<AppointmentType>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(AppointmentType::class)
            ->search('name')
            ->sort('name', 'default_duration_minutes', 'is_active', 'created_at')
            ->boolean('is_active')
            ->paginate();
    }

    /**
     * All active appointment types for the active clinic, ordered by name.
     * ClinicScope filters to the active clinic automatically.
     *
     * @return Collection<int, AppointmentType>
     */
    public function activeForClinic(): Collection
    {
        return AppointmentType::active()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AppointmentType
    {
        return AppointmentType::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AppointmentType $appointmentType, array $data): AppointmentType
    {
        $appointmentType->fill($data)->save();

        return $appointmentType;
    }

    public function delete(AppointmentType $appointmentType): void
    {
        $appointmentType->delete();
    }
}
